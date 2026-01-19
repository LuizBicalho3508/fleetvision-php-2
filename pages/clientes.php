<?php
// --- CONFIGURAÇÃO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(0);

    try {
        if (!isset($_SESSION['user_id'])) throw new Exception("Sessão expirada.");

        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("JSON Inválido.");

        $action = $_GET['action'] ?? '';

        // AÇÃO: SALVAR
        if ($action === 'save') {
            if (empty($input['name'])) throw new Exception("Nome obrigatório.");

            $name = trim($input['name']);
            $doc  = trim($input['document'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $email = trim($input['email'] ?? '');
            $type = $input['type'] ?? 'PJ';
            $price = !empty($input['price_per_vehicle']) ? floatval($input['price_per_vehicle']) : 0.00;
            $due_day = !empty($input['due_day']) ? intval($input['due_day']) : 5;
            $start = !empty($input['contract_start']) ? $input['contract_start'] : date('Y-m-d');
            $end   = !empty($input['contract_end']) ? $input['contract_end'] : date('Y-m-d', strtotime('+1 year'));
            
            $id = (isset($input['id']) && $input['id'] !== "") ? intval($input['id']) : null;

            if ($id) {
                $stmt = $pdo->prepare("UPDATE saas_customers SET name=?, document=?, phone=?, email=?, type=?, price_per_vehicle=?, due_day=?, contract_start=?, contract_end=? WHERE id=? AND tenant_id=?");
                $stmt->execute([$name, $doc, $phone, $email, $type, $price, $due_day, $start, $end, $id, $_SESSION['tenant_id'] ?? $tenant['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO saas_customers (tenant_id, name, document, phone, email, type, price_per_vehicle, due_day, contract_start, contract_end, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$_SESSION['tenant_id'] ?? $tenant['id'], $name, $doc, $phone, $email, $type, $price, $due_day, $start, $end]);
            }
            
            echo json_encode(['success' => true]);
            exit;
        }

        // AÇÃO: EXCLUIR
        if ($action === 'delete') {
            if (empty($input['id'])) throw new Exception("ID inválido.");
            
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM saas_vehicles WHERE client_id = ?");
            $stmtCheck->execute([$input['id']]);
            if ($stmtCheck->fetchColumn() > 0) throw new Exception("Não é possível excluir: Cliente possui veículos vinculados.");

            $stmt = $pdo->prepare("DELETE FROM saas_customers WHERE id=? AND tenant_id=?");
            $stmt->execute([$input['id'], $_SESSION['tenant_id'] ?? $tenant['id']]);
            echo json_encode(['success' => true]);
            exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// --- VIEW (HTML) ---
if (!isset($_SESSION['user_id'])) exit;
$t_id = $_SESSION['tenant_id'] ?? $tenant['id'];

// BUSCA CLIENTES + CONTAGEM
$search = $_GET['search'] ?? '';
$sql = "
    SELECT c.*,
           (SELECT COUNT(*) FROM saas_vehicles v WHERE v.client_id = c.id AND v.status = 'active') as active_vehicles
    FROM saas_customers c 
    WHERE c.tenant_id = ?
";
$params = [$t_id];

if ($search) {
    $sql .= " AND (c.name ILIKE ? OR c.document ILIKE ? OR c.email ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY c.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPIs e Classificação de Status
$totalClients = count($clients);
$totalRevenue = 0;

$countExpired = 0;
$countExpiring = 0;
$countHealthy = 0;

$today = date('Y-m-d');
$next30 = date('Y-m-d', strtotime('+30 days'));

// Processa status para JS e KPIs
foreach($clients as &$c) {
    $totalRevenue += ($c['active_vehicles'] * $c['price_per_vehicle']);
    
    $end = $c['contract_end'];
    if (!$end || $end < $today) {
        $c['status_contract'] = 'expired';
        $countExpired++;
    } elseif ($end <= $next30) {
        $c['status_contract'] = 'expiring';
        $countExpiring++;
    } else {
        $c['status_contract'] = 'healthy';
        $countHealthy++;
    }
}
unset($c); // Limpa referência
?>

<div class="flex flex-col h-screen bg-slate-50 overflow-hidden font-sans">
    
    <div id="toast-area" class="fixed top-20 right-6 z-[9999] flex flex-col gap-3 pointer-events-none transition-all"></div>

    <div class="bg-white border-b border-gray-200 px-8 py-5 shadow-sm z-10 flex-shrink-0">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center text-indigo-600 shadow-sm">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    Gestão de Clientes
                </h1>
                <p class="text-sm text-slate-500 mt-1 ml-14">Contratos e faturamento recorrente.</p>
            </div>
            <button onclick="openModal()" class="btn btn-primary shadow-lg shadow-indigo-200 transform hover:-translate-y-0.5 transition px-6 py-2.5">
                <i class="fas fa-plus mr-2"></i> Novo Cliente
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="bg-white border border-gray-200 p-4 rounded-xl flex items-center gap-4 shadow-sm">
                <div class="w-10 h-10 bg-slate-100 text-slate-600 rounded-lg flex items-center justify-center"><i class="fas fa-users"></i></div>
                <div><p class="text-xs font-bold text-slate-400 uppercase">Clientes Ativos</p><h3 class="text-xl font-bold text-slate-800"><?php echo $totalClients; ?></h3></div>
            </div>
            <div class="bg-white border border-green-100 p-4 rounded-xl flex items-center gap-4 shadow-sm">
                <div class="w-10 h-10 bg-green-50 text-green-600 rounded-lg flex items-center justify-center"><i class="fas fa-dollar-sign"></i></div>
                <div><p class="text-xs font-bold text-green-400 uppercase">Receita Mensal (Estimada)</p><h3 class="text-xl font-bold text-slate-800">R$ <?php echo number_format($totalRevenue, 2, ',', '.'); ?></h3></div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div onclick="openStatusModal('expired')" class="bg-red-50 border border-red-100 p-4 rounded-xl flex items-center justify-between cursor-pointer hover:bg-red-100 transition shadow-sm group">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-red-200 text-red-700 flex items-center justify-center text-lg shadow-sm"><i class="fas fa-times-circle"></i></div>
                    <div>
                        <p class="text-xs font-bold text-red-600 uppercase">Contratos Vencidos</p>
                        <h3 class="text-xl font-bold text-red-800"><?php echo $countExpired; ?></h3>
                    </div>
                </div>
                <i class="fas fa-chevron-right text-red-300 group-hover:text-red-500 transition"></i>
            </div>

            <div onclick="openStatusModal('expiring')" class="bg-yellow-50 border border-yellow-100 p-4 rounded-xl flex items-center justify-between cursor-pointer hover:bg-yellow-100 transition shadow-sm group">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-yellow-200 text-yellow-700 flex items-center justify-center text-lg shadow-sm"><i class="fas fa-exclamation-triangle"></i></div>
                    <div>
                        <p class="text-xs font-bold text-yellow-600 uppercase">A Vencer (30 Dias)</p>
                        <h3 class="text-xl font-bold text-yellow-800"><?php echo $countExpiring; ?></h3>
                    </div>
                </div>
                <i class="fas fa-chevron-right text-yellow-300 group-hover:text-yellow-500 transition"></i>
            </div>

            <div onclick="openStatusModal('healthy')" class="bg-green-50 border border-green-100 p-4 rounded-xl flex items-center justify-between cursor-pointer hover:bg-green-100 transition shadow-sm group">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-green-200 text-green-700 flex items-center justify-center text-lg shadow-sm"><i class="fas fa-check-circle"></i></div>
                    <div>
                        <p class="text-xs font-bold text-green-600 uppercase">Contratos Saudáveis</p>
                        <h3 class="text-xl font-bold text-green-800"><?php echo $countHealthy; ?></h3>
                    </div>
                </div>
                <i class="fas fa-chevron-right text-green-300 group-hover:text-green-500 transition"></i>
            </div>
        </div>
    </div>

    <div class="px-8 py-4 bg-gray-50 border-b border-gray-200">
        <div class="relative max-w-md">
            <i class="fas fa-search absolute left-4 top-3.5 text-gray-400"></i>
            <input type="text" id="search-input" value="<?php echo htmlspecialchars($search); ?>" onkeydown="if(event.key==='Enter') searchClients()" class="w-full pl-10 pr-4 py-2.5 rounded-lg border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition text-sm" placeholder="Buscar por nome, documento ou email...">
        </div>
    </div>

    <div class="flex-1 overflow-y-auto p-8">
        <?php if(empty($clients)): ?>
            <div class="text-center py-20">
                <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-300 text-3xl"><i class="fas fa-user-slash"></i></div>
                <h3 class="text-lg font-bold text-slate-600">Nenhum cliente encontrado</h3>
            </div>
        <?php else: ?>
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 border-b border-gray-200 text-xs uppercase text-gray-500 font-bold">
                        <tr>
                            <th class="px-6 py-4">Cliente / Documento</th>
                            <th class="px-6 py-4">Contato</th>
                            <th class="px-6 py-4 text-center">Fim Contrato</th>
                            <th class="px-6 py-4 text-center">Veículos</th>
                            <th class="px-6 py-4 text-right">Valor Unit.</th>
                            <th class="px-6 py-4 text-right">Total Mensal</th>
                            <th class="px-6 py-4 text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm">
                        <?php foreach($clients as $c): 
                            $monthly = $c['active_vehicles'] * $c['price_per_vehicle'];
                            $endContract = $c['contract_end'] ? date('d/m/Y', strtotime($c['contract_end'])) : '-';
                            
                            $statusClass = 'text-slate-600';
                            if ($c['status_contract'] == 'expired') $statusClass = 'text-red-600 font-bold';
                            if ($c['status_contract'] == 'expiring') $statusClass = 'text-yellow-600 font-bold';
                        ?>
                        <tr class="hover:bg-slate-50 transition group">
                            <td class="px-6 py-4">
                                <div class="font-bold text-slate-800 text-base"><?php echo htmlspecialchars($c['name']); ?></div>
                                <div class="text-xs font-mono text-slate-500"><?php echo htmlspecialchars($c['document'] ?: 'S/ Documento'); ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-slate-700"><i class="fas fa-phone-alt text-gray-400 mr-1"></i> <?php echo htmlspecialchars($c['phone'] ?: '-'); ?></div>
                                <div class="text-slate-500 text-xs mt-0.5"><i class="fas fa-envelope text-gray-400 mr-1"></i> <?php echo htmlspecialchars($c['email'] ?: '-'); ?></div>
                            </td>
                            <td class="px-6 py-4 text-center <?php echo $statusClass; ?>">
                                <?php echo $endContract; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-bold"><?php echo $c['active_vehicles']; ?></span>
                            </td>
                            <td class="px-6 py-4 text-right text-slate-600">
                                R$ <?php echo number_format($c['price_per_vehicle'], 2, ',', '.'); ?>
                            </td>
                            <td class="px-6 py-4 text-right font-bold text-green-600 bg-green-50/30">
                                R$ <?php echo number_format($monthly, 2, ',', '.'); ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex justify-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button onclick='openModal(<?php echo json_encode($c); ?>)' class="p-2 text-blue-500 hover:bg-blue-50 rounded-lg transition" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteClient(<?php echo $c['id']; ?>)" class="p-2 text-red-500 hover:bg-red-50 rounded-lg transition" title="Excluir">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="modal-client" class="fixed inset-0 bg-black/50 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4 transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl transform scale-100 transition-transform duration-300 overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="font-bold text-xl text-slate-800" id="modal-title">Novo Cliente</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-red-500 transition"><i class="fas fa-times text-lg"></i></button>
        </div>
        
        <form onsubmit="saveClient(event)" class="p-6 space-y-4">
            <input type="hidden" id="c-id">
            
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2 md:col-span-1">
                    <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Nome Completo <span class="text-red-500">*</span></label>
                    <input type="text" id="c-name" class="input-std" required placeholder="Ex: Transportadora Silva">
                </div>
                <div class="col-span-2 md:col-span-1">
                    <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Tipo Pessoa</label>
                    <select id="c-type" class="input-std">
                        <option value="PJ">Jurídica (CNPJ)</option>
                        <option value="PF">Física (CPF)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div><label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Documento</label><input type="text" id="c-doc" class="input-std" placeholder="CPF ou CNPJ"></div>
                <div><label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Telefone</label><input type="text" id="c-phone" class="input-std" placeholder="(00) 00000-0000"></div>
                <div><label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Email</label><input type="email" id="c-email" class="input-std" placeholder="cliente@email.com"></div>
            </div>

            <div class="bg-indigo-50 border border-indigo-100 rounded-lg p-4 mt-4">
                <h4 class="text-sm font-bold text-indigo-800 mb-3 flex items-center gap-2"><i class="fas fa-file-invoice-dollar"></i> Dados do Contrato</h4>
                <div class="grid grid-cols-4 gap-4">
                    <div><label class="block text-xs font-bold text-indigo-700 mb-1 uppercase">Valor Unit. (R$)</label><input type="number" step="0.01" id="c-price" class="input-std border-indigo-200" placeholder="0.00"></div>
                    <div><label class="block text-xs font-bold text-indigo-700 mb-1 uppercase">Dia Venc.</label><input type="number" min="1" max="31" id="c-due" class="input-std border-indigo-200" placeholder="Ex: 5"></div>
                    <div><label class="block text-xs font-bold text-indigo-700 mb-1 uppercase">Início</label><input type="date" id="c-start" class="input-std border-indigo-200"></div>
                    <div><label class="block text-xs font-bold text-indigo-700 mb-1 uppercase">Fim</label><input type="date" id="c-end" class="input-std border-indigo-200"></div>
                </div>
            </div>

            <div class="pt-4 mt-2 flex justify-end gap-3 border-t border-gray-100">
                <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary shadow-lg shadow-indigo-200">Salvar Cliente</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-status-list" class="fixed inset-0 bg-black/50 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4 transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[80vh] flex flex-col transform scale-100 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center" id="status-modal-header">
            <h3 class="font-bold text-xl text-slate-800 flex items-center gap-2" id="status-list-title">
                <i class="fas fa-list"></i> Lista de Contratos
            </h3>
            <button onclick="document.getElementById('modal-status-list').classList.add('hidden')" class="text-gray-400 hover:text-red-500 transition text-xl">&times;</button>
        </div>
        <div class="flex-1 overflow-y-auto p-0">
            <table class="w-full text-left border-collapse">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-bold sticky top-0">
                    <tr>
                        <th class="px-6 py-3">Cliente</th>
                        <th class="px-6 py-3">Telefone</th>
                        <th class="px-6 py-3 text-center">Fim Contrato</th>
                        <th class="px-6 py-3 text-center">Ação</th>
                    </tr>
                </thead>
                <tbody id="status-list-body" class="divide-y divide-gray-100 text-sm">
                    </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-gray-100 bg-gray-50 rounded-b-2xl text-right">
            <button onclick="document.getElementById('modal-status-list').classList.add('hidden')" class="btn btn-secondary px-6">Fechar</button>
        </div>
    </div>
</div>

<script>
    // Dados injetados pelo PHP para uso no filtro JS
    const allClients = <?php echo json_encode($clients); ?>;

    // --- LÓGICA DE MODAL DE STATUS ---
    function openStatusModal(type) {
        const modal = document.getElementById('modal-status-list');
        const title = document.getElementById('status-list-title');
        const header = document.getElementById('status-modal-header');
        const tbody = document.getElementById('status-list-body');
        
        let filtered = [];
        let titleText = '';
        let headerColor = '';

        if (type === 'expired') {
            filtered = allClients.filter(c => c.status_contract === 'expired');
            titleText = '<i class="fas fa-times-circle"></i> Contratos Vencidos';
            headerColor = 'bg-red-50';
            title.className = 'font-bold text-xl text-red-700 flex items-center gap-2';
        } else if (type === 'expiring') {
            filtered = allClients.filter(c => c.status_contract === 'expiring');
            titleText = '<i class="fas fa-exclamation-triangle"></i> Contratos a Vencer';
            headerColor = 'bg-yellow-50';
            title.className = 'font-bold text-xl text-yellow-700 flex items-center gap-2';
        } else {
            filtered = allClients.filter(c => c.status_contract === 'healthy');
            titleText = '<i class="fas fa-check-circle"></i> Contratos Saudáveis';
            headerColor = 'bg-green-50';
            title.className = 'font-bold text-xl text-green-700 flex items-center gap-2';
        }

        header.className = `p-6 border-b border-gray-100 flex justify-between items-center ${headerColor}`;
        title.innerHTML = titleText;
        tbody.innerHTML = '';

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="p-8 text-center text-gray-400">Nenhum cliente nesta categoria.</td></tr>';
        } else {
            filtered.forEach(c => {
                const dateEnd = c.contract_end ? new Date(c.contract_end).toLocaleDateString('pt-BR') : '-';
                tbody.innerHTML += `
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-3 font-bold text-slate-700">${c.name}</td>
                        <td class="px-6 py-3 text-slate-600">${c.phone || '-'}</td>
                        <td class="px-6 py-3 text-center font-mono">${dateEnd}</td>
                        <td class="px-6 py-3 text-center">
                            <button onclick='openModal(${JSON.stringify(c)}); document.getElementById("modal-status-list").classList.add("hidden")' class="text-blue-600 hover:text-blue-800 text-xs font-bold underline">
                                Editar
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        modal.classList.remove('hidden');
    }

    // --- FUNÇÕES GERAIS ---
    async function handleResponse(res) {
        const text = await res.text();
        const jsonStart = text.indexOf('{"success"');
        const jsonEnd = text.lastIndexOf('}') + 1;
        if (jsonStart !== -1 && jsonEnd > jsonStart) {
            try { return JSON.parse(text.substring(jsonStart, jsonEnd)); } catch (e) {}
        }
        throw new Error("Erro servidor: " + text.replace(/<[^>]*>/g, '').substring(0, 100));
    }

    function showToast(msg, type='success') {
        const area = document.getElementById('toast-area');
        const color = type === 'success' ? 'border-green-500' : 'border-red-500';
        const icon = type === 'success' ? 'check-circle text-green-500' : 'exclamation-circle text-red-500';
        const toast = document.createElement('div');
        toast.className = `bg-white border-l-4 ${color} shadow-xl p-4 rounded-r-lg flex items-center gap-3 w-80 animate-in slide-in-from-right pointer-events-auto`;
        toast.innerHTML = `<i class="fas fa-${icon} text-xl"></i><p class="text-sm font-bold text-slate-700">${msg}</p>`;
        area.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

    function searchClients() { window.location.href = '?page=clientes&search=' + encodeURIComponent(document.getElementById('search-input').value); }

    function openModal(data = null) {
        document.getElementById('modal-client').classList.remove('hidden');
        if (data) {
            document.getElementById('modal-title').innerText = 'Editar Cliente';
            document.getElementById('c-id').value = data.id;
            document.getElementById('c-name').value = data.name;
            document.getElementById('c-type').value = data.type || 'PJ';
            document.getElementById('c-doc').value = data.document || '';
            document.getElementById('c-phone').value = data.phone || '';
            document.getElementById('c-email').value = data.email || '';
            document.getElementById('c-price').value = data.price_per_vehicle || '';
            document.getElementById('c-due').value = data.due_day || 5;
            document.getElementById('c-start').value = data.contract_start ? data.contract_start.split(' ')[0] : '';
            document.getElementById('c-end').value = data.contract_end ? data.contract_end.split(' ')[0] : '';
        } else {
            document.getElementById('modal-title').innerText = 'Novo Cliente';
            document.getElementById('c-id').value = '';
            document.getElementById('c-name').value = '';
            document.getElementById('c-type').value = 'PJ';
            document.getElementById('c-doc').value = '';
            document.getElementById('c-phone').value = '';
            document.getElementById('c-email').value = '';
            document.getElementById('c-price').value = '';
            document.getElementById('c-due').value = 5;
            document.getElementById('c-start').value = new Date().toISOString().split('T')[0];
            
            const nextYear = new Date();
            nextYear.setFullYear(nextYear.getFullYear() + 1);
            document.getElementById('c-end').value = nextYear.toISOString().split('T')[0];
        }
    }

    function closeModal() { document.getElementById('modal-client').classList.add('hidden'); }

    async function saveClient(e) {
        e.preventDefault();
        const payload = {
            id: document.getElementById('c-id').value,
            name: document.getElementById('c-name').value,
            type: document.getElementById('c-type').value,
            document: document.getElementById('c-doc').value,
            phone: document.getElementById('c-phone').value,
            email: document.getElementById('c-email').value,
            price_per_vehicle: document.getElementById('c-price').value,
            due_day: document.getElementById('c-due').value,
            contract_start: document.getElementById('c-start').value,
            contract_end: document.getElementById('c-end').value
        };
        try {
            const res = await fetch('?page=clientes&action=save', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
            const data = await handleResponse(res);
            if(data.success) { showToast('Salvo com sucesso!'); setTimeout(() => location.reload(), 1000); }
            else showToast(data.error, 'error');
        } catch(e) { showToast(e.message, 'error'); }
    }

    async function deleteClient(id) {
        if(!confirm('Excluir cliente? Se houver veículos vinculados, a exclusão será bloqueada.')) return;
        try {
            const res = await fetch('?page=clientes&action=delete', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: id}) });
            const data = await handleResponse(res);
            if(data.success) { showToast('Cliente excluído.'); setTimeout(() => location.reload(), 1000); }
            else showToast('Erro: ' + data.error, 'error');
        } catch(e) { showToast(e.message, 'error'); }
    }
</script>

<style>
    .input-std { @apply w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-200 transition; }
    .btn { @apply px-4 py-2 rounded-lg font-bold transition text-sm flex items-center justify-center; }
    .btn-primary { @apply bg-indigo-600 text-white hover:bg-indigo-700; }
    .btn-secondary { @apply bg-white border border-gray-300 text-gray-600 hover:bg-gray-50; }
</style>