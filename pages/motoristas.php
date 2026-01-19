<?php
if (!isset($_SESSION['user_id'])) exit;

// --- LÓGICA DE PERMISSÃO E VÍNCULO ---
$isAdmin = ($_SESSION['user_role'] == 'admin' || $_SESSION['user_role'] == 'superadmin');
$userEmail = $_SESSION['user_email'] ?? '';

// Busca ID do Cliente logado (se não for admin)
$loggedCustomerId = null;
if (!$isAdmin) {
    $stmtMe = $pdo->prepare("SELECT id FROM saas_customers WHERE email = ? AND tenant_id = ?");
    $stmtMe->execute([$userEmail, $tenant['id']]);
    $loggedCustomerId = $stmtMe->fetchColumn();
}

// Busca lista de clientes para o Dropdown (Apenas Admin)
$clientesOptions = [];
if ($isAdmin) {
    $stmtC = $pdo->prepare("SELECT id, name FROM saas_customers WHERE tenant_id = ? ORDER BY name");
    $stmtC->execute([$tenant['id']]);
    $clientesOptions = $stmtC->fetchAll(PDO::FETCH_ASSOC);
}

// --- CRUD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $name = $_POST['name'];
    $cnh = $_POST['cnh_number'];
    $rfid = $_POST['rfid_tag'];
    $phone = $_POST['phone'];
    
    // Define quem é o dono do motorista
    if ($isAdmin) {
        $targetCustomer = !empty($_POST['customer_id']) ? $_POST['customer_id'] : null;
    } else {
        $targetCustomer = $loggedCustomerId; // Cliente cadastra para si mesmo
    }

    try {
        if ($action == 'create') {
            $sql = "INSERT INTO saas_drivers (tenant_id, customer_id, name, cnh_number, rfid_tag, phone) VALUES (?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$tenant['id'], $targetCustomer, $name, $cnh, $rfid, $phone]);
            echo "<script>showToast('Motorista cadastrado!', 'success');</script>";
        }

        if ($action == 'update') {
            $sql = "UPDATE saas_drivers SET name=?, cnh_number=?, rfid_tag=?, phone=?, customer_id=? WHERE id=? AND tenant_id=?";
            $pdo->prepare($sql)->execute([$name, $cnh, $rfid, $phone, $targetCustomer, $_POST['id'], $tenant['id']]);
            echo "<script>showToast('Dados atualizados!', 'success');</script>";
        }

        if ($action == 'delete') {
            // Verifica permissão de exclusão (Cliente só deleta o seu)
            $checkSql = "SELECT customer_id FROM saas_drivers WHERE id = ?";
            $stmtCk = $pdo->prepare($checkSql);
            $stmtCk->execute([$_POST['id']]);
            $owner = $stmtCk->fetchColumn();

            if (!$isAdmin && $owner != $loggedCustomerId) {
                echo "<script>showToast('Permissão negada.', 'error');</script>";
            } else {
                $pdo->prepare("DELETE FROM saas_drivers WHERE id=? AND tenant_id=?")->execute([$_POST['id'], $tenant['id']]);
                echo "<script>showToast('Motorista removido.', 'blue');</script>";
            }
        }
    } catch (Exception $e) {
        echo "<script>showToast('Erro: " . $e->getMessage() . "', 'error');</script>";
    }
}

// --- LISTAGEM INTELIGENTE ---
$sql = "SELECT d.*, c.name as customer_name 
        FROM saas_drivers d 
        LEFT JOIN saas_customers c ON d.customer_id = c.id 
        WHERE d.tenant_id = ?";
$params = [$tenant['id']];

// Se for Cliente Final, aplica filtro restritivo
if (!$isAdmin) {
    $sql .= " AND d.customer_id = ?";
    $params[] = $loggedCustomerId;
}

$sql .= " ORDER BY d.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="flex flex-col h-screen bg-slate-50">
    <div class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center shadow-sm">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">Motoristas</h2>
            <p class="text-sm text-slate-500">Gestão de condutores e identificação.</p>
        </div>
        <button onclick="openModal('create')" class="btn btn-primary shadow-lg hover:shadow-xl transition-transform transform hover:-translate-y-0.5">
            <i class="fas fa-user-plus"></i> Novo Motorista
        </button>
    </div>

    <div class="flex-1 overflow-auto px-8 py-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 text-slate-500 font-bold text-xs uppercase tracking-wider border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-4">Nome / Vínculo</th>
                        <th class="px-6 py-4">Identificação (RFID)</th>
                        <th class="px-6 py-4">CNH / Documento</th>
                        <th class="px-6 py-4">Contato</th>
                        <th class="px-6 py-4 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm text-slate-600">
                    <?php if(empty($drivers)): ?>
                        <tr><td colspan="5" class="p-8 text-center text-gray-400 italic">Nenhum motorista encontrado.</td></tr>
                    <?php endif; ?>

                    <?php foreach($drivers as $d): ?>
                    <tr class="hover:bg-slate-50 transition group">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center font-bold border border-slate-200">
                                    <?php echo strtoupper(substr($d['name'], 0, 2)); ?>
                                </div>
                                <div>
                                    <div class="font-bold text-slate-800"><?php echo $d['name']; ?></div>
                                    <?php if($d['customer_name']): ?>
                                        <div class="text-[10px] bg-blue-50 text-blue-600 px-2 py-0.5 rounded inline-block font-bold border border-blue-100 mt-1">
                                            <i class="fas fa-building mr-1"></i> <?php echo $d['customer_name']; ?>
                                        </div>
                                    <?php elseif($isAdmin): ?>
                                        <div class="text-[10px] text-gray-400 italic mt-1">Sem vínculo (Interno)</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <?php if($d['rfid_tag']): ?>
                                <span class="font-mono bg-gray-100 px-2 py-1 rounded text-slate-700 border border-gray-200"><i class="fas fa-wifi text-xs mr-1"></i><?php echo $d['rfid_tag']; ?></span>
                            <?php else: ?>
                                <span class="text-gray-300">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 font-mono text-slate-500"><?php echo $d['cnh_number'] ?: '-'; ?></td>
                        <td class="px-6 py-4"><?php echo $d['phone'] ?: '-'; ?></td>
                        <td class="px-6 py-4 text-right">
                            <button onclick='openEdit(<?php echo json_encode($d); ?>)' class="btn btn-secondary py-1 px-3 text-xs mr-1"><i class="fas fa-edit"></i></button>
                            <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir este motorista?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                <button class="btn btn-secondary py-1 px-3 text-xs text-red-500 hover:text-red-700 hover:bg-red-50 border-red-200"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="modal-driver" class="fixed inset-0 bg-black/60 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="bg-slate-50 px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="font-bold text-lg text-slate-800" id="modal-title">Novo Motorista</h3>
            <button onclick="document.getElementById('modal-driver').classList.add('hidden')" class="text-gray-400 hover:text-red-500 text-xl">&times;</button>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" id="form-action" value="create">
            <input type="hidden" name="id" id="edit-id">
            
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1 uppercase">Nome Completo</label>
                <input type="text" name="name" id="in-name" class="input-std" required placeholder="Ex: João da Silva">
            </div>

            <?php if($isAdmin && !empty($clientesOptions)): ?>
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1 uppercase">Vincular ao Cliente</label>
                <select name="customer_id" id="in-customer" class="input-std bg-yellow-50 border-yellow-200 text-yellow-800">
                    <option value="">-- Uso Interno (Frota Própria) --</option>
                    <?php foreach($clientesOptions as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo $c['name']; ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[10px] text-gray-400 mt-1">Selecione o cliente final dono deste motorista.</p>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase">RFID / Tag (Serial)</label>
                    <input type="text" name="rfid_tag" id="in-rfid" class="input-std font-mono" placeholder="Ex: 0012345">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase">CNH</label>
                    <input type="text" name="cnh_number" id="in-cnh" class="input-std" placeholder="Apenas números">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1 uppercase">Telefone</label>
                <input type="text" name="phone" id="in-phone" class="input-std" placeholder="(99) 99999-9999">
            </div>

            <div class="pt-4 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('modal-driver').classList.add('hidden')" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary px-6">Salvar Cadastro</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(mode){
        document.getElementById('modal-driver').classList.remove('hidden');
        document.getElementById('form-action').value = mode;
        document.getElementById('modal-title').innerText = mode==='create'?'Novo Motorista':'Editar Motorista';
        if(mode==='create') document.forms[0].reset();
    }
    
    function openEdit(d){
        openModal('update');
        document.getElementById('edit-id').value = d.id;
        document.getElementById('in-name').value = d.name;
        document.getElementById('in-rfid').value = d.rfid_tag || '';
        document.getElementById('in-cnh').value = d.cnh_number || '';
        document.getElementById('in-phone').value = d.phone || '';
        
        // Preenche o cliente se o campo existir (apenas admin)
        const selCustomer = document.getElementById('in-customer');
        if(selCustomer) {
            selCustomer.value = d.customer_id || '';
        }
    }
</script>