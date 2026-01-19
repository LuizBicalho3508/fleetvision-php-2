<?php
if (!isset($_SESSION['user_id'])) exit;

// --- LÓGICA DE BACKEND (API EMBUTIDA) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? '';

    try {
        if ($action === 'save') {
            $name = $input['name'];
            $address = $input['address'];
            $phone = $input['phone'];
            $manager = $input['manager'];
            $id = $input['id'] ?? null;

            if ($id) {
                $stmt = $pdo->prepare("UPDATE saas_branches SET name=?, address=?, phone=?, manager_name=? WHERE id=? AND tenant_id=?");
                $stmt->execute([$name, $address, $phone, $manager, $id, $tenant['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO saas_branches (tenant_id, name, address, phone, manager_name, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$tenant['id'], $name, $address, $phone, $manager]);
            }
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'delete') {
            $id = $input['id'];
            // Verifica se tem usuários vinculados antes de deletar (Segurança)
            $check = $pdo->prepare("SELECT COUNT(*) FROM saas_users WHERE branch_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => 'Não é possível excluir: Existem usuários vinculados a esta filial.']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM saas_branches WHERE id=? AND tenant_id=?");
            $stmt->execute([$id, $tenant['id']]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'toggle_status') {
            $id = $input['id'];
            $active = $input['active'] ? 1 : 0; // Se true vira 1, se false vira 0
            
            // Se sua tabela não tem coluna 'is_active', crie-a ou ignore essa parte.
            // Vou assumir que existe ou que vamos criar.
            // SQL SUGERIDO: ALTER TABLE saas_branches ADD COLUMN is_active BOOLEAN DEFAULT TRUE;
            
            // Verificação de segurança da coluna
            $stmt = $pdo->prepare("UPDATE saas_branches SET is_active = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$active, $id, $tenant['id']]);
            echo json_encode(['success' => true]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// --- LÓGICA DE VIEW (BUSCA DADOS) ---
// Garante que a coluna is_active exista na query, se não existir no banco, assumimos 1
$branches = [];
try {
    $sql = "SELECT *, COALESCE(is_active, true) as active_status FROM saas_branches WHERE tenant_id = ? ORDER BY id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tenant['id']]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback se a tabela não tiver is_active ainda
    $sql = "SELECT * FROM saas_branches WHERE tenant_id = ? ORDER BY id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tenant['id']]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($branches as &$b) $b['active_status'] = true;
}

$total = count($branches);
$activeCount = count(array_filter($branches, function($b){ return $b['active_status']; }));
?>

<div class="flex flex-col h-screen bg-slate-50 overflow-hidden">
    
    <div class="bg-white border-b border-gray-200 px-8 py-5 shadow-sm z-10">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Gestão de Filiais</h1>
                <p class="text-sm text-slate-500">Administre as unidades da sua operação.</p>
            </div>
            <button onclick="openModal()" class="btn btn-primary shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition">
                <i class="fas fa-plus"></i> Nova Filial
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-blue-50 border border-blue-100 p-4 rounded-xl flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center text-xl"><i class="fas fa-building"></i></div>
                <div>
                    <p class="text-xs font-bold text-blue-400 uppercase">Total de Unidades</p>
                    <h3 class="text-2xl font-bold text-slate-800"><?php echo $total; ?></h3>
                </div>
            </div>
            <div class="bg-green-50 border border-green-100 p-4 rounded-xl flex items-center gap-4">
                <div class="w-12 h-12 bg-green-100 text-green-600 rounded-lg flex items-center justify-center text-xl"><i class="fas fa-check-circle"></i></div>
                <div>
                    <p class="text-xs font-bold text-green-400 uppercase">Operando / Ativas</p>
                    <h3 class="text-2xl font-bold text-slate-800"><?php echo $activeCount; ?></h3>
                </div>
            </div>
            <div class="flex items-center">
                <div class="relative w-full">
                    <i class="fas fa-search absolute left-4 top-3.5 text-gray-400"></i>
                    <input type="text" id="search-branch" onkeyup="filterBranches()" class="w-full pl-10 pr-4 py-3 rounded-xl border border-gray-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition" placeholder="Buscar por nome, cidade ou gerente...">
                </div>
            </div>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto p-8">
        <?php if(empty($branches)): ?>
            <div class="text-center py-20">
                <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-300 text-3xl"><i class="fas fa-store-slash"></i></div>
                <h3 class="text-lg font-bold text-slate-600">Nenhuma filial cadastrada</h3>
                <p class="text-slate-400 text-sm mt-1">Comece criando sua primeira unidade.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="branches-grid">
                <?php foreach($branches as $b): 
                    $statusColor = $b['active_status'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500';
                    $statusText = $b['active_status'] ? 'Ativa' : 'Inativa';
                    $opacity = $b['active_status'] ? '' : 'opacity-60';
                ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition group branch-card <?php echo $opacity; ?>" data-search="<?php echo strtolower($b['name'] . ' ' . $b['address'] . ' ' . $b['manager_name']); ?>">
                    <div class="flex justify-between items-start mb-4">
                        <div class="w-12 h-12 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl font-bold shadow-sm">
                            <?php echo strtoupper(substr($b['name'], 0, 2)); ?>
                        </div>
                        <div class="flex gap-2">
                            <span class="px-2 py-1 rounded text-[10px] font-bold uppercase <?php echo $statusColor; ?>"><?php echo $statusText; ?></span>
                            <div class="relative">
                                <button class="text-gray-300 hover:text-gray-600 p-1 transition" onclick="toggleMenu('menu-<?php echo $b['id']; ?>')"><i class="fas fa-ellipsis-v"></i></button>
                                <div id="menu-<?php echo $b['id']; ?>" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-xl border border-gray-100 z-20 py-1 text-sm">
                                    <a href="#" onclick="openModal(<?php echo htmlspecialchars(json_encode($b)); ?>); closeMenu('menu-<?php echo $b['id']; ?>')" class="block px-4 py-2 hover:bg-gray-50 text-gray-700"><i class="fas fa-edit mr-2 text-blue-500"></i> Editar</a>
                                    <a href="#" onclick="toggleStatus(<?php echo $b['id']; ?>, <?php echo $b['active_status']?0:1; ?>); closeMenu('menu-<?php echo $b['id']; ?>')" class="block px-4 py-2 hover:bg-gray-50 text-gray-700"><i class="fas fa-power-off mr-2 text-orange-500"></i> <?php echo $b['active_status'] ? 'Desativar' : 'Ativar'; ?></a>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="#" onclick="deleteBranch(<?php echo $b['id']; ?>); closeMenu('menu-<?php echo $b['id']; ?>')" class="block px-4 py-2 hover:bg-red-50 text-red-600"><i class="fas fa-trash mr-2"></i> Excluir</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h3 class="text-lg font-bold text-slate-800 mb-1"><?php echo htmlspecialchars($b['name']); ?></h3>
                    <p class="text-sm text-gray-500 mb-4 h-10 overflow-hidden"><i class="fas fa-map-marker-alt mr-1 text-gray-300"></i> <?php echo htmlspecialchars($b['address'] ?: 'Endereço não informado'); ?></p>

                    <div class="border-t border-gray-100 pt-4 mt-2 grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-[10px] uppercase font-bold text-gray-400 mb-1">Gerente</p>
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center text-[10px] text-gray-500"><i class="fas fa-user"></i></div>
                                <span class="text-xs font-bold text-slate-700 truncate"><?php echo htmlspecialchars($b['manager_name'] ?: '-'); ?></span>
                            </div>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase font-bold text-gray-400 mb-1">Telefone</p>
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 rounded-full bg-green-50 flex items-center justify-center text-[10px] text-green-600"><i class="fas fa-phone"></i></div>
                                <span class="text-xs font-bold text-slate-700 truncate"><?php echo htmlspecialchars($b['phone'] ?: '-'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="modal-branch" class="fixed inset-0 bg-black/60 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg animate-in zoom-in duration-200">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50 rounded-t-xl">
            <h3 class="font-bold text-lg text-slate-800" id="modal-title">Nova Filial</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-red-500 transition"><i class="fas fa-times text-xl"></i></button>
        </div>
        
        <form id="form-branch" onsubmit="saveBranch(event)" class="p-6 space-y-4">
            <input type="hidden" id="b-id">
            
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Nome da Unidade <span class="text-red-500">*</span></label>
                <input type="text" id="b-name" class="input-std" required placeholder="Ex: Matriz, Filial Zona Sul...">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Endereço Completo</label>
                <input type="text" id="b-address" class="input-std" placeholder="Rua, Número, Bairro, Cidade - UF">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Nome do Gerente</label>
                    <input type="text" id="b-manager" class="input-std" placeholder="Responsável">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Telefone / WhatsApp</label>
                    <input type="text" id="b-phone" class="input-std" placeholder="(00) 00000-0000">
                </div>
            </div>

            <div class="pt-4 flex justify-end gap-3 border-t border-gray-100 mt-2">
                <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary px-6">Salvar Dados</button>
            </div>
        </form>
    </div>
</div>

<script>
    // --- UI HELPERS ---
    function filterBranches() {
        const term = document.getElementById('search-branch').value.toLowerCase();
        document.querySelectorAll('.branch-card').forEach(card => {
            const text = card.getAttribute('data-search');
            card.style.display = text.includes(term) ? 'block' : 'none';
        });
    }

    function toggleMenu(id) {
        document.querySelectorAll('[id^="menu-"]').forEach(m => {
            if(m.id !== id) m.classList.add('hidden');
        });
        document.getElementById(id).classList.toggle('hidden');
    }
    
    // Fecha menus ao clicar fora
    window.onclick = function(e) {
        if (!e.target.closest('button')) {
            document.querySelectorAll('[id^="menu-"]').forEach(m => m.classList.add('hidden'));
        }
    }

    // --- CRUD ---
    function openModal(data = null) {
        document.getElementById('modal-branch').classList.remove('hidden');
        document.getElementById('form-branch').reset();
        
        if (data) {
            document.getElementById('modal-title').innerText = 'Editar Filial';
            document.getElementById('b-id').value = data.id;
            document.getElementById('b-name').value = data.name;
            document.getElementById('b-address').value = data.address || '';
            document.getElementById('b-manager').value = data.manager_name || '';
            document.getElementById('b-phone').value = data.phone || '';
        } else {
            document.getElementById('modal-title').innerText = 'Nova Filial';
            document.getElementById('b-id').value = '';
        }
        document.getElementById('b-name').focus();
    }

    function closeModal() {
        document.getElementById('modal-branch').classList.add('hidden');
    }

    async function saveBranch(e) {
        e.preventDefault();
        const payload = {
            id: document.getElementById('b-id').value,
            name: document.getElementById('b-name').value,
            address: document.getElementById('b-address').value,
            manager: document.getElementById('b-manager').value,
            phone: document.getElementById('b-phone').value
        };

        try {
            const res = await fetch('?page=filiais&action=save', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            
            if(data.success) {
                location.reload();
            } else {
                alert('Erro: ' + (data.error || 'Falha ao salvar.'));
            }
        } catch(err) { alert('Erro de conexão.'); }
    }

    async function deleteBranch(id) {
        if(!confirm("Tem certeza? Isso pode afetar usuários vinculados.")) return;
        
        try {
            const res = await fetch('?page=filiais&action=delete', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            });
            const data = await res.json();
            
            if(data.success) {
                location.reload();
            } else {
                alert('Erro: ' + (data.error || 'Não foi possível excluir.'));
            }
        } catch(err) { alert('Erro de conexão.'); }
    }

    async function toggleStatus(id, active) {
        try {
            const res = await fetch('?page=filiais&action=toggle_status', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id, active: active})
            });
            const data = await res.json();
            if(data.success) location.reload();
        } catch(err) { alert('Erro ao alterar status.'); }
    }
</script>