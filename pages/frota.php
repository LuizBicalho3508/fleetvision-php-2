<?php
// Inclui conexão centralizada
require 'db.php';

// Verifica sessão
if (!isset($_SESSION['user_id'])) exit;
$user_id = $_SESSION['user_id'];
$tenant_id = $_SESSION['tenant_id'];
$user_role = $_SESSION['user_role'] ?? 'user';

// Identifica se é Cliente Final (Para restringir acesso)
$logged_client_id = null;
if ($user_role !== 'admin' && $user_role !== 'superadmin') {
    $stmtMe = $pdo->prepare("SELECT customer_id FROM saas_users WHERE id = ?");
    $stmtMe->execute([$user_id]);
    $logged_client_id = $stmtMe->fetchColumn();
}

// --- LOGICA DE POST (SALVAR, EXCLUIR, STATUS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Limpa buffer para garantir JSON limpo
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    ini_set('display_errors', 0);

    // Se for cliente final, bloqueia qualquer ação de escrita
    if ($logged_client_id) { 
        echo json_encode(['success' => false, 'error' => 'Permissão negada. Apenas leitura.']); 
        exit; 
    }

    try {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("JSON Inválido.");

        $action = $_GET['action'] ?? '';

        // AÇÃO: SALVAR
        if ($action === 'save') {
            if (empty($input['name'])) throw new Exception("Nome do veículo é obrigatório.");

            $name = trim($input['name']);
            $plate = trim($input['plate'] ?? '');
            $model = trim($input['model'] ?? '');
            
            // Categoria agora salva o ID do ícone personalizado
            $category = $input['category'] ?? null; 
            
            $client_id = (isset($input['client_id']) && $input['client_id'] !== "") ? intval($input['client_id']) : null;
            $device_id = (isset($input['traccar_device_id']) && $input['traccar_device_id'] !== "") ? intval($input['traccar_device_id']) : null;
            $idle = (isset($input['idle_threshold']) && $input['idle_threshold'] !== "") ? intval($input['idle_threshold']) : 5;
            $id = (isset($input['id']) && $input['id'] !== "") ? intval($input['id']) : null;

            // Busca Identificador no Estoque
            $identifier = null;
            if ($device_id) {
                $stmtImei = $pdo->prepare("SELECT COALESCE(identifier, imei) FROM saas_stock WHERE traccar_device_id = ?");
                $stmtImei->execute([$device_id]);
                $identifier = $stmtImei->fetchColumn();
                
                // Valida Duplicidade
                $checkSql = "SELECT id, name FROM saas_vehicles WHERE (traccar_device_id = ? OR identifier = ?) AND tenant_id = ?";
                $paramsCheck = [$device_id, $identifier, $tenant_id];
                
                if ($id) { 
                    $checkSql .= " AND id != ?"; 
                    $paramsCheck[] = $id; 
                }
                
                $stmtCheck = $pdo->prepare($checkSql);
                $stmtCheck->execute($paramsCheck);
                if ($dup = $stmtCheck->fetch()) throw new Exception("Rastreador já está no veículo: " . $dup['name']);
            }

            // Gera ID temporário se não tiver rastreador
            if (!$identifier) {
                if ($id) {
                    $stmtOld = $pdo->prepare("SELECT identifier FROM saas_vehicles WHERE id = ?");
                    $stmtOld->execute([$id]);
                    $identifier = $stmtOld->fetchColumn();
                }
                if (!$identifier) $identifier = "TEMP-" . time() . "-" . rand(1000,9999);
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE saas_vehicles SET name=?, plate=?, model=?, category=?, traccar_device_id=?, client_id=?, idle_threshold=?, identifier=? WHERE id=? AND tenant_id=?");
                $stmt->execute([$name, $plate, $model, $category, $device_id, $client_id, $idle, $identifier, $id, $tenant_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO saas_vehicles (tenant_id, name, plate, model, category, traccar_device_id, client_id, idle_threshold, identifier, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
                $stmt->execute([$tenant_id, $name, $plate, $model, $category, $device_id, $client_id, $idle, $identifier]);
            }
            
            echo json_encode(['success' => true]);
            exit;
        }

        // AÇÃO: EXCLUIR
        if ($action === 'delete') {
            if (empty($input['id'])) throw new Exception("ID inválido.");
            $stmt = $pdo->prepare("DELETE FROM saas_vehicles WHERE id=? AND tenant_id=?");
            $stmt->execute([$input['id'], $tenant_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        // AÇÃO: STATUS (Ativar/Desativar)
        if ($action === 'toggle_status') {
            $status = $input['active'] ? 'active' : 'inactive';
            $stmt = $pdo->prepare("UPDATE saas_vehicles SET status = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$status, $input['id'], $tenant_id]);
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

// 1. Busca Veículos (JOIN com ícones personalizados e Filtro de Cliente)
$search = $_GET['search'] ?? '';
$sql = "
    SELECT v.*, 
           COALESCE(s.identifier, s.imei, v.identifier, 'S/ Rastreador') as imei_display, 
           s.model as tracker_model,
           c.name as client_name,
           icon.url as icon_url,
           icon.name as icon_name
    FROM saas_vehicles v 
    LEFT JOIN saas_stock s ON (v.traccar_device_id = s.traccar_device_id OR v.identifier = s.identifier)
    LEFT JOIN saas_customers c ON v.client_id = c.id
    LEFT JOIN saas_custom_icons icon ON CAST(v.category AS VARCHAR) = CAST(icon.id AS VARCHAR)
    WHERE v.tenant_id = ?
";
$params = [$tenant_id];

// >>> FILTRO DE CLIENTE FINAL <<<
if ($logged_client_id) {
    $sql .= " AND v.client_id = ?";
    $params[] = $logged_client_id;
}

if ($search) {
    $sql .= " AND (v.name ILIKE ? OR v.plate ILIKE ? OR s.identifier ILIKE ? OR c.name ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY v.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Carrega listas auxiliares APENAS se for Admin (para popular o modal)
$stockList = [];
$clientsList = [];
$customIcons = [];

if (!$logged_client_id) {
    // Busca Estoque (COM VERIFICAÇÃO DE USO)
    $stmtStock = $pdo->prepare("
        SELECT s.traccar_device_id, s.identifier, s.imei, s.model,
               (SELECT COUNT(*) FROM saas_vehicles v WHERE v.identifier = s.identifier AND v.tenant_id = s.tenant_id) as is_used
        FROM saas_stock s 
        WHERE s.tenant_id = ? 
        ORDER BY (s.traccar_device_id IS NOT NULL) DESC, s.identifier ASC
    ");
    $stmtStock->execute([$tenant_id]);
    $stockList = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

    // Busca Clientes
    $stmtClients = $pdo->prepare("SELECT id, name FROM saas_customers WHERE tenant_id = ? ORDER BY name ASC");
    $stmtClients->execute([$tenant_id]);
    $clientsList = $stmtClients->fetchAll(PDO::FETCH_ASSOC);

    // Busca Ícones
    $stmtIcons = $pdo->prepare("SELECT id, name, url FROM saas_custom_icons WHERE tenant_id = ? OR tenant_id IS NULL ORDER BY id DESC");
    $stmtIcons->execute([$tenant_id]);
    $customIcons = $stmtIcons->fetchAll(PDO::FETCH_ASSOC);
}

// KPIs
$total = count($vehicles);
$active = count(array_filter($vehicles, fn($v) => $v['status'] === 'active'));
?>

<div class="flex flex-col h-screen bg-slate-50 overflow-hidden font-sans">
    
    <div id="toast-area" class="fixed top-20 right-6 z-[9999] flex flex-col gap-3 pointer-events-none transition-all"></div>

    <div class="bg-white border-b border-gray-200 px-8 py-5 shadow-sm z-10 flex-shrink-0">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center text-blue-600 shadow-sm">
                        <i class="fas fa-truck"></i>
                    </div>
                    Gestão de Frota
                </h1>
                <p class="text-sm text-slate-500 mt-1 ml-14">Veículos, Clientes e Rastreadores vinculados.</p>
            </div>
            
            <?php if(!$logged_client_id): ?>
            <button onclick="openModal()" class="btn btn-primary shadow-lg shadow-blue-200 transform hover:-translate-y-0.5 transition px-6 py-2.5">
                <i class="fas fa-plus mr-2"></i> Novo Veículo
            </button>
            <?php endif; ?>
        </div>

        <div class="flex gap-4 items-center bg-gray-50 p-3 rounded-xl border border-gray-200">
            <div class="flex-1 relative">
                <i class="fas fa-search absolute left-4 top-3.5 text-gray-400"></i>
                <input type="text" id="search-input" value="<?php echo htmlspecialchars($search); ?>" onkeydown="if(event.key==='Enter') searchVehicles()" class="w-full pl-10 pr-4 py-2.5 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition text-sm" placeholder="Buscar por nome, placa, cliente ou IMEI...">
            </div>
            <div class="flex gap-4 px-4 border-l border-gray-200">
                <div class="text-center">
                    <span class="block text-xs font-bold text-gray-400 uppercase">Total</span>
                    <span class="block text-xl font-bold text-slate-700"><?php echo $total; ?></span>
                </div>
                <div class="text-center">
                    <span class="block text-xs font-bold text-green-500 uppercase">Ativos</span>
                    <span class="block text-xl font-bold text-green-600"><?php echo $active; ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto p-8">
        <?php if(empty($vehicles)): ?>
            <div class="text-center py-20">
                <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-300 text-3xl"><i class="fas fa-truck"></i></div>
                <h3 class="text-lg font-bold text-slate-600">Nenhum veículo encontrado</h3>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach($vehicles as $v): 
                    $isActive = $v['status'] === 'active';
                    $statusClass = $isActive ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500';
                    $opacity = $isActive ? '' : 'opacity-70 grayscale';
                    
                    $imei = $v['imei_display'];
                    $imeiDisplay = ($imei && $imei !== 'S/ Rastreador') ? '<span class="font-mono text-slate-700 font-bold">'.$imei.'</span>' : '<span class="text-red-400 italic text-xs">Sem Rastreador</span>';
                    $clientName = $v['client_name'] ?? 'Sem Cliente';
                    
                    // Ícone dinâmico do banco de dados
                    $iconUrl = $v['icon_url'];
                ?>
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition group relative overflow-hidden <?php echo $opacity; ?>">
                    
                    <?php if(!$logged_client_id): ?>
                    <div class="absolute top-4 right-4">
                        <button class="text-gray-300 hover:text-blue-600 transition p-1" onclick="toggleMenu('menu-<?php echo $v['id']; ?>')"><i class="fas fa-ellipsis-v"></i></button>
                        <div id="menu-<?php echo $v['id']; ?>" class="hidden absolute right-0 mt-2 w-40 bg-white rounded-lg shadow-xl border border-gray-100 z-20 py-1 text-sm animate-in fade-in zoom-in duration-200">
                            <a href="#" onclick='openModal(<?php echo json_encode($v); ?>)' class="block px-4 py-2 hover:bg-gray-50 text-gray-700"><i class="fas fa-edit mr-2 text-blue-500"></i> Editar</a>
                            <a href="#" onclick="toggleStatus(<?php echo $v['id']; ?>, <?php echo $isActive ? 'false' : 'true'; ?>)" class="block px-4 py-2 hover:bg-gray-50 text-gray-700"><i class="fas fa-power-off mr-2 text-orange-500"></i> <?php echo $isActive ? 'Desativar' : 'Ativar'; ?></a>
                            <div class="border-t border-gray-100 my-1"></div>
                            <a href="#" onclick="deleteVehicle(<?php echo $v['id']; ?>)" class="block px-4 py-2 hover:bg-red-50 text-red-600"><i class="fas fa-trash mr-2"></i> Excluir</a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-12 h-12 rounded-xl bg-slate-50 flex items-center justify-center overflow-hidden border border-slate-100">
                            <?php if($iconUrl): ?>
                                <img src="<?php echo $iconUrl; ?>" class="w-8 h-8 object-contain">
                            <?php else: ?>
                                <i class="fas fa-truck text-slate-400 text-xl"></i>
                            <?php endif; ?>
                        </div>
                        <div class="overflow-hidden">
                            <h3 class="font-bold text-slate-800 leading-tight truncate" title="<?php echo htmlspecialchars($v['name']); ?>"><?php echo htmlspecialchars($v['name']); ?></h3>
                            <span class="text-xs font-mono bg-slate-100 px-1.5 py-0.5 rounded text-slate-500 mt-1 inline-block"><?php echo htmlspecialchars($v['plate'] ?: 'S/ Placa'); ?></span>
                        </div>
                    </div>

                    <div class="space-y-2 text-xs text-gray-500 border-t border-gray-100 pt-4 mt-2">
                        <div class="flex items-center gap-2" title="Cliente Vinculado">
                            <i class="fas fa-user text-blue-400 w-4 text-center"></i>
                            <span class="font-bold text-slate-600 truncate"><?php echo htmlspecialchars($clientName); ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-microchip text-orange-400 w-4 text-center"></i>
                            <div class="flex-1 flex justify-between items-center bg-slate-50 p-1.5 rounded border border-slate-100">
                                <?php echo $imeiDisplay; ?>
                                <span class="text-[9px] bg-white border border-gray-200 px-1 rounded text-gray-500"><?php echo $v['tracker_model'] ?? '-'; ?></span>
                            </div>
                        </div>
                        <div class="flex justify-between items-center pt-2">
                            <div><p class="uppercase font-bold text-[9px] text-gray-400">Modelo</p><p class="text-slate-700"><?php echo htmlspecialchars($v['model'] ?: '-'); ?></p></div>
                            <div class="text-right"><p class="uppercase font-bold text-[9px] text-gray-400">Ociosidade</p><p class="text-slate-700"><?php echo $v['idle_threshold'] ?? 5; ?> min</p></div>
                        </div>
                    </div>
                    <div class="absolute top-4 left-4">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase <?php echo $statusClass; ?>">
                            <?php echo $isActive ? 'Ativo' : 'Inativo'; ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if(!$logged_client_id): ?>
<div id="modal-vehicle" class="fixed inset-0 bg-black/50 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4 transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg transform scale-100 transition-transform duration-300 overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="font-bold text-xl text-slate-800" id="modal-title">Novo Veículo</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-red-500 transition"><i class="fas fa-times text-lg"></i></button>
        </div>
        
        <form onsubmit="saveVehicle(event)" class="p-6 space-y-4">
            <input type="hidden" id="v-id">
            <input type="hidden" id="v-category" value=""> <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-blue-700 mb-1 uppercase">Cliente</label>
                    <select id="v-client" class="input-std">
                        <option value="">-- Selecione --</option>
                        <?php foreach($clientsList as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="relative">
                    <label class="block text-xs font-bold text-blue-700 mb-1 uppercase">Ícone</label>
                    <div class="input-std flex items-center justify-between cursor-pointer" onclick="toggleIconList()">
                        <div class="flex items-center gap-2" id="selected-icon-display">
                            <span class="text-gray-400 text-xs">Selecione...</span>
                        </div>
                        <i class="fas fa-chevron-down text-gray-400"></i>
                    </div>
                    
                    <div id="icon-list" class="hidden absolute top-full left-0 w-full bg-white border border-gray-200 rounded-lg shadow-xl mt-1 z-50 p-2 grid grid-cols-4 gap-2 max-h-48 overflow-y-auto">
                        <?php foreach($customIcons as $icon): ?>
                            <div class="p-2 border border-gray-100 rounded hover:bg-blue-50 cursor-pointer flex flex-col items-center gap-1 transition icon-option" 
                                 onclick="selectIcon('<?php echo $icon['id']; ?>', '<?php echo $icon['url']; ?>', '<?php echo $icon['name']; ?>')">
                                <img src="<?php echo $icon['url']; ?>" class="w-8 h-8 object-contain">
                                <span class="text-[9px] text-center text-gray-600 leading-tight"><?php echo substr($icon['name'], 0, 8); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Nome <span class="text-red-500">*</span></label>
                    <input type="text" id="v-name" class="input-std" required placeholder="Ex: Frota 01">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Placa</label>
                    <input type="text" id="v-plate" class="input-std uppercase" placeholder="ABC-1234">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Modelo</label>
                    <input type="text" id="v-model" class="input-std" placeholder="Ex: Gol G5">
                </div>
            </div>

            <div class="bg-blue-50 border border-blue-100 rounded-lg p-4">
                <div class="mb-3 relative">
                    <label class="block text-xs font-bold text-blue-700 mb-1 uppercase">Vincular Rastreador</label>
                    <input type="text" id="tracker-search" class="input-std border-blue-200 focus:border-blue-500 font-mono" placeholder="Pesquisar IMEI ou Modelo..." onkeyup="filterTrackers()" onfocus="showTrackerList()" autocomplete="off">
                    <input type="hidden" id="v-traccar-id">

                    <div id="tracker-list" class="hidden absolute w-full bg-white border border-gray-200 rounded-lg shadow-xl max-h-48 overflow-y-auto z-50 mt-1">
                        <div class="p-2 hover:bg-gray-50 cursor-pointer text-xs text-gray-500 border-b border-gray-100" onclick="selectTracker('', '')">
                            <i class="fas fa-times mr-2"></i> Desvincular / Nenhum
                        </div>
                        <?php foreach($stockList as $s): 
                            $displayImei = $s['identifier'] ?: ($s['imei'] ?: 'S/ IMEI');
                            $deviceId = $s['traccar_device_id'];
                            $model = $s['model'] ?: 'Genérico';
                            
                            $usedClass = $s['is_used'] > 0 ? 'used-tracker' : '';
                            $usedStyle = $s['is_used'] > 0 ? 'display: none;' : '';
                            $usedLabel = $s['is_used'] > 0 ? '<span class="text-red-400 text-[9px]">(Em Uso)</span>' : '';
                        ?>
                        <div class="tracker-option p-3 hover:bg-blue-50 cursor-pointer border-b border-gray-50 transition <?php echo $usedClass; ?>" 
                             style="<?php echo $usedStyle; ?>"
                             data-search="<?php echo $displayImei . ' ' . strtolower($model); ?>"
                             data-id="<?php echo $deviceId; ?>"
                             onclick="selectTracker('<?php echo $deviceId; ?>', '<?php echo $displayImei; ?> - <?php echo $model; ?>')">
                            <div class="flex justify-between items-center">
                                <div>
                                    <span class="font-bold text-slate-700 text-sm font-mono"><?php echo $displayImei; ?></span>
                                    <?php echo $usedLabel; ?>
                                </div>
                                <span class="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded block"><?php echo $model; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div id="no-tracker-msg" class="p-3 text-xs text-gray-400 text-center hidden">Nenhum disponível</div>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-blue-700 mb-1 uppercase">Limite Ociosidade (Min)</label>
                    <input type="number" id="v-idle" class="input-std border-blue-200 focus:border-blue-500" value="5">
                </div>
            </div>

            <div class="pt-4 mt-2 flex justify-end gap-3 border-t border-gray-100">
                <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary shadow-lg shadow-blue-200">Salvar Veículo</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    // --- LÓGICA DE ÍCONES ---
    function toggleIconList() {
        document.getElementById('icon-list').classList.toggle('hidden');
    }
    
    function selectIcon(id, url, name) {
        document.getElementById('v-category').value = id;
        document.getElementById('selected-icon-display').innerHTML = `
            <img src="${url}" class="w-6 h-6 object-contain">
            <span class="text-sm font-bold text-slate-700">${name}</span>
        `;
        document.getElementById('icon-list').classList.add('hidden');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#icon-list') && !e.target.closest('.input-std')) {
            const list = document.getElementById('icon-list');
            if (list) list.classList.add('hidden');
        }
    });

    // --- LÓGICA GERAL ---
    function filterTrackers() {
        const term = document.getElementById('tracker-search').value.toLowerCase();
        const options = document.querySelectorAll('.tracker-option');
        options.forEach(opt => {
            if (opt.classList.contains('hidden-used')) { opt.style.display = 'none'; return; }
            if (opt.getAttribute('data-search').includes(term)) { opt.style.display = 'block'; } 
            else { opt.style.display = 'none'; }
        });
        showTrackerList();
    }

    async function handleResponse(res) {
        const text = await res.text();
        const jsonStart = text.indexOf('{"success"');
        const jsonEnd = text.lastIndexOf('}') + 1;
        if (jsonStart !== -1 && jsonEnd > jsonStart) {
            try { return JSON.parse(text.substring(jsonStart, jsonEnd)); } catch (e) {}
        }
        throw new Error("Erro servidor: " + text.replace(/<[^>]*>/g, '').substring(0, 100));
    }

    function showTrackerList() { document.getElementById('tracker-list').classList.remove('hidden'); }
    function selectTracker(id, text) {
        document.getElementById('v-traccar-id').value = id;
        document.getElementById('tracker-search').value = text;
        document.getElementById('tracker-list').classList.add('hidden');
    }
    
    document.addEventListener('click', function(e) {
        const ts = document.getElementById('tracker-search');
        if (ts && !ts.contains(e.target) && !document.getElementById('tracker-list').contains(e.target)) {
            document.getElementById('tracker-list').classList.add('hidden');
        }
    });

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

    function searchVehicles() { window.location.href = '?page=frota&search=' + encodeURIComponent(document.getElementById('search-input').value); }

    function openModal(data = null) {
        document.getElementById('modal-vehicle').classList.remove('hidden');
        
        // Reset rastreadores usados
        document.querySelectorAll('.used-tracker').forEach(el => {
            el.style.display = 'none';
            el.classList.add('hidden-used');
        });

        if (data) {
            document.getElementById('modal-title').innerText = 'Editar Veículo';
            document.getElementById('v-id').value = data.id;
            document.getElementById('v-name').value = data.name;
            document.getElementById('v-plate').value = data.plate || '';
            document.getElementById('v-model').value = data.model || '';
            document.getElementById('v-idle').value = data.idle_threshold || 5;
            document.getElementById('v-client').value = data.client_id || '';
            
            // Popula Ícone
            if(data.icon_url && data.category) {
                selectIcon(data.category, data.icon_url, data.icon_name || 'Ícone Atual');
            } else {
                document.getElementById('v-category').value = '';
                const display = document.getElementById('selected-icon-display');
                if(display) display.innerHTML = '<span class="text-gray-400 text-xs">Selecione...</span>';
            }

            // Libera rastreador atual
            if(data.traccar_device_id) {
                const currentOpt = document.querySelector(`.tracker-option[data-id="${data.traccar_device_id}"]`);
                if (currentOpt) { currentOpt.style.display = 'block'; currentOpt.classList.remove('hidden-used'); }
                const txt = (data.imei_display !== 'S/ Rastreador' ? data.imei_display : '') + (data.tracker_model ? ' - ' + data.tracker_model : '');
                selectTracker(data.traccar_device_id, txt);
            } else {
                selectTracker('', '');
                document.getElementById('tracker-search').value = '';
            }
        } else {
            document.getElementById('modal-title').innerText = 'Novo Veículo';
            document.getElementById('v-id').value = '';
            document.getElementById('v-name').value = '';
            document.getElementById('v-plate').value = '';
            document.getElementById('v-model').value = '';
            document.getElementById('v-category').value = '';
            const display = document.getElementById('selected-icon-display');
            if(display) display.innerHTML = '<span class="text-gray-400 text-xs">Selecione...</span>';
            document.getElementById('v-idle').value = 5;
            document.getElementById('v-client').value = '';
            selectTracker('', '');
            document.getElementById('tracker-search').value = '';
        }
    }

    function closeModal() { document.getElementById('modal-vehicle').classList.add('hidden'); }

    async function saveVehicle(e) {
        e.preventDefault();
        const payload = {
            id: document.getElementById('v-id').value,
            name: document.getElementById('v-name').value,
            plate: document.getElementById('v-plate').value,
            model: document.getElementById('v-model').value,
            category: document.getElementById('v-category').value,
            traccar_device_id: document.getElementById('v-traccar-id').value,
            idle_threshold: document.getElementById('v-idle').value,
            client_id: document.getElementById('v-client').value
        };
        try {
            const res = await fetch('?page=frota&action=save', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
            const data = await handleResponse(res);
            if(data.success) { showToast('Salvo com sucesso!'); setTimeout(() => location.reload(), 1000); }
            else showToast(data.error, 'error');
        } catch(e) { showToast(e.message, 'error'); }
    }

    async function deleteVehicle(id) {
        if(!confirm('Excluir veículo?')) return;
        try {
            const res = await fetch('?page=frota&action=delete', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: id}) });
            const data = await handleResponse(res);
            if(data.success) { showToast('Veículo excluído.'); setTimeout(() => location.reload(), 1000); }
            else showToast('Erro: ' + data.error, 'error');
        } catch(e) { showToast(e.message, 'error'); }
    }

    async function toggleStatus(id, active) {
        try {
            const res = await fetch('?page=frota&action=toggle_status', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: id, active: active}) });
            const data = await handleResponse(res);
            if(data.success) location.reload();
        } catch(e) { showToast('Erro ao alterar status', 'error'); }
    }

    function toggleMenu(id) { 
        document.querySelectorAll('[id^="menu-"]').forEach(el => { if(el.id !== id) el.classList.add('hidden'); }); 
        const menu = document.getElementById(id);
        if (menu) menu.classList.toggle('hidden'); 
    }
    
    window.onclick = function(e) { 
        if(!e.target.closest('button')) document.querySelectorAll('[id^="menu-"]').forEach(el => el.classList.add('hidden')); 
    }
</script>

<style>
    .input-std { @apply w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-200 transition; }
    .btn { @apply px-4 py-2 rounded-lg font-bold transition text-sm flex items-center justify-center; }
    .btn-primary { @apply bg-blue-600 text-white hover:bg-blue-700; }
    .btn-secondary { @apply bg-white border border-gray-300 text-gray-600 hover:bg-gray-50; }
</style>