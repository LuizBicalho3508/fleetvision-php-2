<?php
require 'db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['tenant_id'])) {
    header("Location: login.php");
    exit;
}

// MATRIZ DE PERMISSÕES GRANULARES (CRUD)
$system_modules = [
    'Monitoramento' => [
        'map_view' => 'Acessar Mapa em Tempo Real',
        'map_history' => 'Visualizar Histórico de Rotas',
        'alerts_view' => 'Visualizar Alertas Disparados',
        'commands_send' => 'Enviar Comandos (Bloqueio/Desbloqueio)',
    ],
    'Frota (Veículos)' => [
        'vehicles_view' => 'Visualizar Lista de Veículos',
        'vehicles_add' => 'Cadastrar Novos Veículos',
        'vehicles_edit' => 'Editar Dados do Veículo',
        'vehicles_delete' => 'Excluir Veículos',
    ],
    'Clientes' => [
        'customers_view' => 'Visualizar Clientes',
        'customers_add' => 'Cadastrar Clientes',
        'customers_edit' => 'Editar Clientes',
        'customers_delete' => 'Excluir Clientes',
        'customers_login' => 'Logar como Cliente (Impersonate)',
    ],
    'Financeiro' => [
        'financial_view' => 'Visualizar Faturas/Boletos',
        'financial_edit' => 'Gerar/Editar Cobranças',
        'financial_delete' => 'Cancelar Cobranças',
    ],
    'Administração' => [
        'users_manage' => 'Gerenciar Usuários do Sistema',
        'roles_manage' => 'Criar/Editar Perfis de Acesso',
        'reports_view' => 'Acessar Relatórios Gerenciais',
        'audit_log' => 'Ver Logs de Auditoria',
    ],
    'Técnico' => [
        'tech_stock' => 'Gestão de Estoque (Rastreadores)',
        'tech_test' => 'Acesso ao Laboratório de Testes',
        'tech_config' => 'Configurações de Hardware/Ícones',
    ]
];
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Perfis</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .checkbox-wrapper input:checked + div { background-color: #4f46e5; border-color: #4f46e5; }
        .checkbox-wrapper input:checked + div svg { display: block; }
    </style>
</head>
<body class="bg-slate-50 font-sans text-slate-800">

    <div class="p-8 max-w-7xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-slate-800">Perfis de Acesso</h1>
                <p class="text-slate-500 mt-1">Defina granularmente o que cada usuário pode fazer.</p>
            </div>
            <button onclick="openModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl font-bold shadow-lg transition flex items-center gap-2">
                <i class="fas fa-plus"></i> Novo Perfil
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="profiles-grid">
            <div class="col-span-full py-20 text-center text-slate-400">
                <i class="fas fa-spinner fa-spin text-3xl"></i><br>Carregando...
            </div>
        </div>
    </div>

    <div id="modal-role" class="fixed inset-0 bg-black/80 hidden z-50 flex items-center justify-center backdrop-blur-sm transition-opacity opacity-0">
        <div class="bg-white w-full max-w-5xl h-[90vh] rounded-2xl shadow-2xl flex flex-col transform scale-95 transition-transform duration-300" id="modal-content">
            
            <div class="px-8 py-5 border-b border-slate-200 flex justify-between items-center bg-slate-50 rounded-t-2xl flex-shrink-0">
                <div><h3 class="text-xl font-bold text-slate-800" id="modal-title">Novo Perfil</h3></div>
                <button onclick="closeModal()" class="text-slate-400 hover:text-red-500 text-2xl">&times;</button>
            </div>

            <div class="flex-1 overflow-y-auto p-8 custom-scroll">
                <form id="role-form">
                    <input type="hidden" id="role_id">
                    
                    <div class="mb-8">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Nome do Perfil</label>
                        <input type="text" id="role_name" class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:border-indigo-500 outline-none transition font-bold text-lg" placeholder="Ex: Operador Nível 1" required>
                    </div>

                    <div class="space-y-8">
                        <?php foreach ($system_modules as $group => $perms): ?>
                        <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                            <div class="flex items-center gap-3 mb-4 pb-2 border-b border-slate-100">
                                <i class="fas fa-layer-group text-indigo-500"></i>
                                <h4 class="font-bold text-slate-700 text-lg"><?php echo $group; ?></h4>
                                <button type="button" onclick="toggleGroup(this)" class="text-xs text-indigo-600 hover:underline ml-auto">Marcar Todos</button>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($perms as $key => $label): ?>
                                <label class="flex items-start gap-3 cursor-pointer checkbox-wrapper group select-none hover:bg-slate-50 p-2 rounded-lg transition">
                                    <div class="relative flex items-center h-5 mt-0.5">
                                        <input type="checkbox" name="permissions[]" value="<?php echo $key; ?>" class="hidden peer">
                                        <div class="w-5 h-5 border-2 border-slate-300 rounded flex items-center justify-center transition-colors peer-checked:bg-indigo-600 peer-checked:border-indigo-600 bg-white">
                                            <svg class="w-3 h-3 text-white hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                                        </div>
                                    </div>
                                    <span class="text-sm text-slate-600 group-hover:text-slate-900 font-medium"><?php echo $label; ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>

            <div class="px-8 py-5 border-t border-slate-200 bg-slate-50 rounded-b-2xl flex justify-between items-center flex-shrink-0">
                <button type="button" onclick="deleteProfile()" id="btn-delete" class="text-red-500 hover:text-red-700 text-sm font-bold hidden flex items-center gap-2">
                    <i class="fas fa-trash"></i> Excluir Perfil
                </button>
                <div class="flex gap-3 ml-auto">
                    <button onclick="closeModal()" class="px-6 py-2.5 rounded-xl border border-slate-300 text-slate-600 font-bold hover:bg-white transition">Cancelar</button>
                    <button onclick="saveProfile()" class="px-6 py-2.5 rounded-xl bg-indigo-600 text-white font-bold hover:bg-indigo-700 shadow-lg shadow-indigo-200 transition">Salvar Alterações</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Correção de Rota (Sobe um nível para achar api_dados.php na raiz)
        const API_URL = '../api_dados.php';

        // Helpers de Interface
        const modal = document.getElementById('modal-role');
        const content = document.getElementById('modal-content');

        function toggleGroup(btn) {
            const group = btn.closest('.bg-white');
            const checkboxes = group.querySelectorAll('input[type="checkbox"]');
            const allChecked = Array.from(checkboxes).every(c => c.checked);
            checkboxes.forEach(c => c.checked = !allChecked);
            btn.innerText = allChecked ? "Marcar Todos" : "Desmarcar Todos";
        }

        function openModal() {
            document.getElementById('role_id').value = '';
            document.getElementById('role_name').value = '';
            document.querySelectorAll('input[type="checkbox"]').forEach(el => el.checked = false);
            document.getElementById('modal-title').innerText = "Novo Perfil de Acesso";
            document.getElementById('btn-delete').classList.add('hidden');
            
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                content.classList.remove('scale-95');
                content.classList.add('scale-100');
            }, 10);
        }

        function closeModal() {
            content.classList.remove('scale-100');
            content.classList.add('scale-95');
            modal.classList.add('opacity-0');
            setTimeout(() => modal.classList.add('hidden'), 300);
        }

        function editProfile(role) {
            openModal();
            document.getElementById('modal-title').innerText = "Editar: " + role.name;
            document.getElementById('btn-delete').classList.remove('hidden');
            
            document.getElementById('role_id').value = role.id;
            document.getElementById('role_name').value = role.name;

            let perms = [];
            try { perms = typeof role.permissions === 'string' ? JSON.parse(role.permissions) : role.permissions; } catch(e){}
            
            perms.forEach(key => {
                const c = document.querySelector(`input[value="${key}"]`);
                if(c) c.checked = true;
            });
        }

        async function saveProfile() {
            const id = document.getElementById('role_id').value;
            const name = document.getElementById('role_name').value;
            const permissions = Array.from(document.querySelectorAll('input[name="permissions[]"]:checked')).map(el => el.value);

            if (!name) return alert("Digite o nome do perfil.");

            const btn = document.querySelector('button[onclick="saveProfile()"]');
            const originalText = btn.innerText;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            btn.disabled = true;

            try {
                const res = await fetch(API_URL + '?action=save_profile', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id, name, permissions })
                });
                
                if(!res.ok) throw new Error("Erro HTTP: " + res.status);
                
                const data = await res.json();
                if (data.success) {
                    closeModal();
                    loadProfiles();
                } else {
                    alert('Erro: ' + (data.error || 'Falha ao salvar'));
                }
            } catch (e) {
                console.error(e);
                alert('Erro ao salvar. Verifique se o arquivo api_dados.php existe na raiz.');
            } finally {
                btn.innerText = originalText;
                btn.disabled = false;
            }
        }

        async function deleteProfile() {
            const id = document.getElementById('role_id').value;
            if(!id || !confirm("Tem certeza? Usuários com este perfil perderão os acessos.")) return;
            try {
                const res = await fetch(API_URL + '?action=delete_profile', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ id })
                });
                if ((await res.json()).success) { closeModal(); loadProfiles(); } else { alert('Erro ao excluir.'); }
            } catch (e) { alert('Erro conexão.'); }
        }

        document.addEventListener('DOMContentLoaded', loadProfiles);

        async function loadProfiles() {
            const grid = document.getElementById('profiles-grid');
            try {
                const res = await fetch(API_URL + '?action=get_profiles');
                if(!res.ok) throw new Error(`Erro na API: ${res.status}`);
                const data = await res.json();

                if(data.length === 0) {
                    grid.innerHTML = `<div class="col-span-full py-20 text-center text-slate-400 border-2 border-dashed border-slate-200 rounded-2xl bg-white">
                        <i class="fas fa-user-shield text-4xl mb-4 text-slate-300"></i><br>Nenhum perfil de acesso criado.
                    </div>`;
                    return;
                }

                grid.innerHTML = data.map(role => {
                    let perms = [];
                    try { perms = typeof role.permissions === 'string' ? JSON.parse(role.permissions) : role.permissions; } catch(e){}
                    // Sanitização para evitar quebra do JSON no onclick
                    const safeRole = JSON.stringify(role).replace(/"/g, '&quot;');
                    
                    return `
                    <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm hover:shadow-md transition group relative cursor-pointer" onclick="editProfile(${safeRole})">
                        <div class="absolute top-4 right-4 text-slate-300 group-hover:text-indigo-500 transition">
                            <i class="fas fa-pencil-alt"></i>
                        </div>
                        <div class="flex items-center gap-4 mb-4">
                            <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl shadow-sm border border-indigo-100">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-slate-800 leading-tight">${role.name}</h3>
                                <p class="text-xs text-slate-500 font-medium">${perms.length} permissões ativas</p>
                            </div>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                            <div class="bg-gradient-to-r from-indigo-500 to-purple-500 h-1.5 rounded-full" style="width: ${Math.min(perms.length*2, 100)}%"></div>
                        </div>
                    </div>`;
                }).join('');

            } catch (e) {
                console.error(e);
                grid.innerHTML = `<div class="col-span-full text-center text-red-500 bg-white p-6 rounded-xl border border-red-200">
                    <i class="fas fa-exclamation-triangle text-2xl mb-2"></i><br>
                    <b>Erro de conexão.</b><br>
                    <small class="text-slate-500">Não foi possível carregar os perfis.</small>
                </div>`;
            }
        }
    </script>
</body>
</html>