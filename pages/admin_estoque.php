<?php
if (!isset($_SESSION['user_id'])) exit;

// --- LÓGICA DE SINCRONIZAÇÃO INTELIGENTE ---
function syncTraccar($pdo, $imei, $model, $saasUserId) {
    if (empty($imei)) return null;
    $deviceName = "$model - $imei";

    // 1. Busca ou Cria o Dispositivo (tc_devices)
    $stmt = $pdo->prepare("SELECT id FROM tc_devices WHERE uniqueid = ?");
    $stmt->execute([$imei]);
    $deviceId = $stmt->fetchColumn();

    if ($deviceId) {
        // Atualiza nome se já existe
        $pdo->prepare("UPDATE tc_devices SET name = ? WHERE id = ?")->execute([$deviceName, $deviceId]);
    } else {
        // Cria novo
        $stmtIns = $pdo->prepare("INSERT INTO tc_devices (name, uniqueid) VALUES (?, ?) RETURNING id");
        $stmtIns->execute([$deviceName, $imei]);
        $deviceId = $stmtIns->fetchColumn();
    }

    // 2. VINCULA AUTOMATICAMENTE (SOLUÇÃO DE ESCALABILIDADE)
    
    // Lista de IDs de usuários do Traccar que DEVEM ver esse dispositivo.
    // O ID 1 geralmente é o ADMIN GERAL. Adicione outros IDs fixos aqui se precisar.
    $usersToLink = [1]; 

    // Adiciona dinamicamente o usuário logado no SaaS (se ele existir no Traccar)
    $stmtSaasUser = $pdo->prepare("SELECT email FROM saas_users WHERE id = ?");
    $stmtSaasUser->execute([$saasUserId]);
    $userEmail = $stmtSaasUser->fetchColumn();

    if ($userEmail) {
        $stmtTcUser = $pdo->prepare("SELECT id FROM tc_users WHERE email = ?");
        $stmtTcUser->execute([$userEmail]);
        $traccarUserId = $stmtTcUser->fetchColumn();
        if ($traccarUserId) {
            $usersToLink[] = $traccarUserId;
        }
    }

    // Executa os vínculos (Loop para garantir que todos vejam)
    $stmtLink = $pdo->prepare("INSERT INTO tc_user_device (userid, deviceid) VALUES (?, ?) ON CONFLICT DO NOTHING");
    
    foreach (array_unique($usersToLink) as $uid) {
        $stmtLink->execute([$uid, $deviceId]);
    }

    return $deviceId;
}

// --- CRUD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'];
        $tenant_id = $_SESSION['tenant_id'] ?? $tenant['id'];

        // --- CREATE ---
        if ($action == 'create') {
            $pdo->beginTransaction();

            $identifier = trim($_POST['identifier']);
            $model = trim($_POST['model']);
            $type = $_POST['type'];

            // Sincroniza Traccar se for rastreador
            $traccarId = null;
            if ($type == 'tracker') {
                $traccarId = syncTraccar($pdo, $identifier, $model, $_SESSION['user_id']);
            }

            $sql = "INSERT INTO saas_stock (tenant_id, type, brand, model, identifier, supplier, status, notes, traccar_device_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([
                $tenant_id, 
                $type, 
                $_POST['brand'], 
                $model, 
                $identifier, 
                $_POST['supplier'], 
                $_POST['status'], 
                $_POST['notes'],
                $traccarId
            ]);

            $pdo->commit();
            echo "<script>showToast('Item criado e vinculado ao Admin!', 'green');</script>";
        }
        
        // --- UPDATE ---
        if ($action == 'update') {
            $pdo->beginTransaction();
            
            $id = $_POST['id'];
            $identifier = trim($_POST['identifier']);
            $model = trim($_POST['model']);
            $type = $_POST['type'];

            $traccarId = null;
            if ($type == 'tracker') {
                // Recupera ID antigo ou cria novo/vincula
                $traccarId = syncTraccar($pdo, $identifier, $model, $_SESSION['user_id']);
            }

            $sql = "UPDATE saas_stock SET type=?, brand=?, model=?, identifier=?, supplier=?, status=?, notes=?, traccar_device_id=? WHERE id=? AND tenant_id=?";
            $pdo->prepare($sql)->execute([
                $type, 
                $_POST['brand'], 
                $model, 
                $identifier, 
                $_POST['supplier'], 
                $_POST['status'], 
                $_POST['notes'], 
                $traccarId,
                $id, 
                $tenant_id
            ]);

            $pdo->commit();
            echo "<script>showToast('Item atualizado!', 'blue');</script>";
        }
        
        // --- DELETE ---
        if ($action == 'delete') {
            $pdo->beginTransaction();
            $id = $_POST['id'];

            // Pega ID Traccar antes de apagar
            $stmtGet = $pdo->prepare("SELECT traccar_device_id FROM saas_stock WHERE id = ? AND tenant_id = ?");
            $stmtGet->execute([$id, $tenant_id]);
            $tcId = $stmtGet->fetchColumn();

            // Apaga do Estoque
            $pdo->prepare("DELETE FROM saas_stock WHERE id=? AND tenant_id=?")->execute([$id, $tenant_id]);

            // Apaga do Traccar (Limpeza Total)
            if ($tcId) {
                // Remove vínculos de usuário primeiro (Constraints)
                $pdo->prepare("DELETE FROM tc_user_device WHERE deviceid = ?")->execute([$tcId]);
                // Remove o dispositivo
                $pdo->prepare("DELETE FROM tc_devices WHERE id = ?")->execute([$tcId]);
            }

            $pdo->commit();
            echo "<script>showToast('Item removido completamente.', 'red');</script>";
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "<script>showToast('Erro: " . addslashes($e->getMessage()) . "', 'red');</script>";
    }
}

// --- BUSCA DE DADOS ---
$sql = "SELECT s.*, v.plate as linked_plate, v.name as linked_vehicle 
        FROM saas_stock s 
        LEFT JOIN saas_vehicles v ON (s.traccar_device_id = v.traccar_device_id OR s.identifier = v.identifier) AND v.tenant_id = s.tenant_id
        WHERE s.tenant_id = ? 
        ORDER BY s.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$_SESSION['tenant_id'] ?? $tenant['id']]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$total = count($items);
$trackers = 0; $sims = 0; $available = 0; $installed = 0;

foreach($items as $i) {
    if($i['type'] == 'tracker') $trackers++;
    if($i['type'] == 'sim') $sims++;
    if($i['linked_plate']) $installed++;
    else if($i['status'] == 'available') $available++;
}
?>

<div class="flex flex-col h-screen bg-slate-50">
    <div id="toast-container"></div>

    <div class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center shadow-sm z-10">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">Controle de Estoque</h2>
            <p class="text-sm text-slate-500">Gerencie Rastreadores (Sync Traccar), Chips e Insumos.</p>
        </div>
        <button onclick="openModal('create')" class="btn btn-primary shadow-lg hover:shadow-xl transition-transform transform hover:-translate-y-0.5">
            <i class="fas fa-box-open"></i> Novo Item
        </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 px-8 py-6">
        <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-gray-400 uppercase">Total de Itens</p><h3 class="text-2xl font-bold text-slate-700"><?php echo $total; ?></h3></div>
            <div class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center text-lg"><i class="fas fa-layer-group"></i></div>
        </div>
        <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-gray-400 uppercase">Disponíveis</p><h3 class="text-2xl font-bold text-green-600"><?php echo $available; ?></h3></div>
            <div class="w-10 h-10 rounded-full bg-green-50 text-green-600 flex items-center justify-center text-lg"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-gray-400 uppercase">Em Uso / Instalados</p><h3 class="text-2xl font-bold text-slate-700"><?php echo $installed; ?></h3></div>
            <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center text-lg"><i class="fas fa-truck"></i></div>
        </div>
        <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
            <div><p class="text-xs font-bold text-gray-400 uppercase">Chips M2M</p><h3 class="text-2xl font-bold text-purple-600"><?php echo $sims; ?></h3></div>
            <div class="w-10 h-10 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center text-lg"><i class="fas fa-sim-card"></i></div>
        </div>
    </div>

    <div class="px-8 pb-4 flex justify-between items-center">
        <div class="flex bg-white p-1 rounded-lg border border-gray-200 shadow-sm">
            <button onclick="filterType('all')" class="px-4 py-1.5 rounded-md text-sm font-bold text-slate-600 hover:bg-slate-50 transition filter-btn active" id="tab-all">Todos</button>
            <button onclick="filterType('tracker')" class="px-4 py-1.5 rounded-md text-sm font-bold text-slate-500 hover:bg-slate-50 transition filter-btn" id="tab-tracker">Rastreadores</button>
            <button onclick="filterType('sim')" class="px-4 py-1.5 rounded-md text-sm font-bold text-slate-500 hover:bg-slate-50 transition filter-btn" id="tab-sim">Chips</button>
        </div>

        <div class="relative w-72">
            <i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-xs"></i>
            <input type="text" id="search-stock" onkeyup="searchTable()" placeholder="Buscar IMEI, ICCID ou Modelo..." class="input-std pl-9 py-2 text-xs">
        </div>
    </div>

    <div class="flex-1 overflow-auto px-8 pb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 text-slate-500 font-bold text-xs uppercase tracking-wider border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-4">Tipo / Modelo</th>
                        <th class="px-6 py-4">Identificador (IMEI/ICCID)</th>
                        <th class="px-6 py-4">Fornecedor</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 text-center">Sync Traccar</th>
                        <th class="px-6 py-4 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm text-slate-600" id="stock-list">
                    <?php if(empty($items)): ?>
                        <tr><td colspan="6" class="p-8 text-center text-gray-400 italic">Estoque vazio.</td></tr>
                    <?php endif; ?>

                    <?php foreach($items as $i): 
                        $icon = 'fa-box'; $color='slate';
                        if($i['type'] == 'tracker') { $icon='fa-microchip'; $color='blue'; }
                        if($i['type'] == 'sim') { $icon='fa-sim-card'; $color='purple'; }
                        if($i['type'] == 'accessory') { $icon='fa-plug'; $color='orange'; }

                        $statusBadge = '<span class="bg-green-100 text-green-700 px-2 py-1 rounded text-xs font-bold">Disponível</span>';
                        if($i['linked_plate']) {
                            $statusBadge = '<span class="bg-indigo-100 text-indigo-700 px-2 py-1 rounded text-xs font-bold" title="Em: '.$i['linked_vehicle'].'">Instalado: '.$i['linked_plate'].'</span>';
                        } elseif ($i['status'] == 'maintenance') {
                            $statusBadge = '<span class="bg-yellow-100 text-yellow-700 px-2 py-1 rounded text-xs font-bold">Manutenção</span>';
                        } elseif ($i['status'] == 'defective') {
                            $statusBadge = '<span class="bg-red-100 text-red-700 px-2 py-1 rounded text-xs font-bold">Defeituoso</span>';
                        }

                        $traccarIcon = ($i['traccar_device_id']) 
                            ? '<i class="fas fa-check-circle text-green-500" title="Sincronizado"></i>' 
                            : '<i class="fas fa-times-circle text-gray-300" title="Não Sincronizado"></i>';
                    ?>
                    <tr class="hover:bg-slate-50 transition group row-item" data-type="<?php echo $i['type']; ?>" data-search="<?php echo strtolower($i['identifier'].' '.$i['model'].' '.$i['brand']); ?>">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-500 flex items-center justify-center border border-<?php echo $color; ?>-100">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div>
                                    <div class="font-bold text-slate-800"><?php echo $i['model']; ?></div>
                                    <div class="text-xs text-gray-400"><?php echo $i['brand']; ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 font-mono text-slate-600 font-bold"><?php echo $i['identifier']; ?></td>
                        <td class="px-6 py-4"><?php echo $i['supplier'] ?: '-'; ?></td>
                        <td class="px-6 py-4 text-center"><?php echo $statusBadge; ?></td>
                        <td class="px-6 py-4 text-center"><?php echo $traccarIcon; ?></td>
                        <td class="px-6 py-4 text-right">
                            <button onclick='openEdit(<?php echo json_encode($i); ?>)' class="btn btn-secondary py-1 px-3 text-xs mr-1"><i class="fas fa-pencil-alt"></i></button>
                            <form method="POST" class="inline" onsubmit="return confirm('Remover item? Se for rastreador, ele será removido do Traccar para todos.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $i['id']; ?>">
                                <button class="btn btn-secondary py-1 px-3 text-xs text-red-500 hover:bg-red-50 border-red-200"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="modal-stock" class="fixed inset-0 bg-black/60 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="bg-slate-50 px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="font-bold text-lg text-slate-800" id="modal-title">Novo Item</h3>
            <button onclick="document.getElementById('modal-stock').classList.add('hidden')" class="text-gray-400 hover:text-red-500 text-xl">&times;</button>
        </div>
        
        <form method="POST" class="p-6">
            <input type="hidden" name="action" id="form-action" value="create">
            <input type="hidden" name="id" id="edit-id">

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Tipo</label>
                    <select name="type" id="in-type" class="input-std">
                        <option value="tracker">Rastreador</option>
                        <option value="sim">Chip M2M</option>
                        <option value="accessory">Acessório/Outro</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Status</label>
                    <select name="status" id="in-status" class="input-std">
                        <option value="available">Disponível</option>
                        <option value="maintenance">Manutenção</option>
                        <option value="defective">Defeituoso</option>
                        <option value="installed">Instalado (Manual)</option>
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 mb-1">Identificador Único (IMEI / ICCID / Serial) *</label>
                <input type="text" name="identifier" id="in-identifier" class="input-std font-mono bg-yellow-50 border-yellow-200" required>
                <p class="text-[10px] text-gray-400 mt-1">Se for rastreador, este IMEI será criado e vinculado automaticamente.</p>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div><label class="block text-xs font-bold text-gray-500 mb-1">Marca</label><input type="text" name="brand" id="in-brand" class="input-std" placeholder="Ex: Suntech"></div>
                <div><label class="block text-xs font-bold text-gray-500 mb-1">Modelo</label><input type="text" name="model" id="in-model" class="input-std" placeholder="Ex: ST310"></div>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 mb-1">Fornecedor (Opcional)</label>
                <input type="text" name="supplier" id="in-supplier" class="input-std">
            </div>

            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 mb-1">Observações</label>
                <textarea name="notes" id="in-notes" rows="2" class="input-std"></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('modal-stock').classList.add('hidden')" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary px-6">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showToast(msg, type) {
        const t = document.createElement('div');
        t.className = 'toast';
        t.style.borderLeftColor = type === 'red' ? '#ef4444' : (type === 'green' ? '#22c55e' : '#3b82f6');
        t.innerHTML = msg;
        document.getElementById('toast-container').appendChild(t);
        setTimeout(() => t.remove(), 4000);
    }

    function openModal(mode){
        document.getElementById('modal-stock').classList.remove('hidden');
        document.getElementById('form-action').value=mode;
        document.getElementById('modal-title').innerText=mode=='create'?'Novo Item':'Editar Item';
        if(mode=='create') document.forms[0].reset();
    }
    function openEdit(d){
        openModal('update');
        document.getElementById('edit-id').value=d.id;
        document.getElementById('in-type').value=d.type;
        document.getElementById('in-status').value=d.status;
        document.getElementById('in-identifier').value=d.identifier;
        document.getElementById('in-brand').value=d.brand;
        document.getElementById('in-model').value=d.model;
        document.getElementById('in-supplier').value=d.supplier;
        document.getElementById('in-notes').value=d.notes;
    }
    
    function filterType(type){
        document.querySelectorAll('.filter-btn').forEach(b=>{
            b.classList.remove('active','text-blue-600','bg-blue-50');
            b.classList.add('text-slate-500');
        });
        document.getElementById('tab-'+type).classList.add('active','text-blue-600','bg-blue-50');
        document.getElementById('tab-'+type).classList.remove('text-slate-500');

        document.querySelectorAll('.row-item').forEach(row=>{
            if(type=='all' || row.dataset.type==type) row.style.display=''; else row.style.display='none';
        });
    }

    function searchTable(){
        const term = document.getElementById('search-stock').value.toLowerCase();
        document.querySelectorAll('.row-item').forEach(row=>{
            const txt = row.dataset.search;
            row.style.display = txt.includes(term) ? '' : 'none';
        });
    }
</script>

<style>
    .filter-btn.active { border-bottom: 2px solid var(--primary); border-radius: 0; }
</style>