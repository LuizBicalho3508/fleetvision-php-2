<?php
if (!isset($_SESSION['user_id'])) exit;
// NÃO inclua sidebar.php ou header.php aqui, pois já são carregados no index.php
?>

<div class="h-full flex flex-col bg-slate-50 relative overflow-hidden font-inter">
    
    <div class="px-8 py-6 bg-white border-b border-slate-200 flex justify-between items-center shadow-sm z-20 shrink-0">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-3">
                <div class="p-2 bg-blue-50 rounded-lg text-blue-600"><i class="fas fa-users"></i></div>
                Gestão de Usuários
            </h1>
            <p class="text-sm text-slate-500 mt-1 ml-11">Administre o acesso e permissões da equipe.</p>
        </div>
        
        <div class="flex items-center gap-3">
            <button onclick="loadUsers()" class="p-2.5 text-slate-400 hover:text-indigo-600 bg-white border border-slate-200 hover:border-indigo-200 rounded-lg transition shadow-sm" title="Atualizar">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button onclick="openModalUser()" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg text-sm font-bold shadow-md transition flex items-center gap-2">
                <i class="fas fa-plus"></i> Novo Usuário
            </button>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto custom-scroll p-8">
        <div class="max-w-[1600px] mx-auto">
            
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden flex flex-col">
                <div class="px-6 py-4 border-b border-slate-100 flex gap-4 items-center bg-slate-50/50">
                    <div class="relative w-full max-w-md">
                        <i class="fas fa-search absolute left-3 top-3 text-slate-400"></i>
                        <input type="text" id="user-search" placeholder="Buscar por nome ou email..." 
                               class="w-full pl-10 pr-4 py-2 rounded-lg border border-slate-300 focus:border-blue-500 outline-none text-sm bg-white transition"
                               onkeyup="filterUsers()">
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 font-bold border-b border-slate-200">
                            <tr>
                                <th class="p-4 pl-6">Usuário</th>
                                <th class="p-4">Email</th>
                                <th class="p-4">Função / Perfil</th>
                                <th class="p-4">Cliente / Filial</th>
                                <th class="p-4 text-center">Status</th>
                                <th class="p-4 pr-6 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="user-list" class="text-sm divide-y divide-slate-100 text-slate-600">
                            <tr><td colspan="6" class="p-8 text-center text-slate-400"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<div id="modal-user" class="fixed inset-0 bg-black/60 hidden z-[100] flex items-center justify-center p-4 backdrop-blur-sm transition-opacity opacity-0">
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden transform scale-95 transition-all duration-300" id="modal-user-content">
        <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="font-bold text-lg text-slate-800" id="modal-title">Novo Usuário</h3>
            <button onclick="closeModal('modal-user')" class="w-8 h-8 rounded-full hover:bg-red-50 text-slate-400 hover:text-red-500 flex items-center justify-center transition"><i class="fas fa-times"></i></button>
        </div>
        
        <form id="form-user" onsubmit="saveUser(event)" class="p-6 space-y-4">
            <input type="hidden" id="user-id">
            
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">NOME COMPLETO</label>
                <input type="text" id="user-name" required class="w-full p-3 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:border-blue-500 outline-none transition text-sm">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">EMAIL DE ACESSO</label>
                <input type="email" id="user-email" required class="w-full p-3 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:border-blue-500 outline-none transition text-sm">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">SENHA</label>
                    <input type="password" id="user-pass" class="w-full p-3 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:border-blue-500 outline-none transition text-sm" placeholder="Opcional se edição">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">PERFIL (ROLE)</label>
                    <select id="user-role" class="w-full p-3 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:border-blue-500 outline-none transition text-sm cursor-pointer">
                        <option value="">Selecione...</option>
                        </select>
                </div>
            </div>

            <div class="flex items-center gap-3 mt-2 bg-slate-50 p-3 rounded-xl border border-slate-100">
                <input type="checkbox" id="user-active" class="w-5 h-5 text-blue-600 rounded focus:ring-blue-500 cursor-pointer" checked>
                <label for="user-active" class="text-sm font-bold text-slate-600 cursor-pointer select-none">Usuário Ativo</label>
            </div>

            <div class="pt-4 flex justify-end gap-3 border-t border-slate-100 mt-2">
                <button type="button" onclick="closeModal('modal-user')" class="px-5 py-2.5 rounded-xl border border-slate-200 text-slate-500 font-bold text-sm hover:bg-slate-50 transition">Cancelar</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-sm hover:bg-blue-700 shadow-md transition">Salvar Usuário</button>
            </div>
        </form>
    </div>
</div>

<script>
    const API_URL = 'api_dados.php'; // Rota relativa correta
    let allUsers = [];

    document.addEventListener('DOMContentLoaded', () => {
        loadUsers();
        loadRoles();
    });

    async function loadUsers() {
        const list = document.getElementById('user-list');
        try {
            const res = await fetch(`${API_URL}?action=get_users`);
            const data = await res.json();
            
            if(data.error) throw new Error(data.error);
            if(!data.length) { list.innerHTML = '<tr><td colspan="6" class="p-8 text-center text-slate-400 italic">Nenhum usuário encontrado.</td></tr>'; return; }

            allUsers = data;
            renderUsers(data);
        } catch(e) {
            console.error(e);
            list.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-red-500">Erro: ${e.message}</td></tr>`;
        }
    }

    async function loadRoles() {
        try {
            const res = await fetch(`${API_URL}?action=get_profiles`);
            const data = await res.json();
            const sel = document.getElementById('user-role');
            sel.innerHTML = '<option value="">Selecione...</option>' + data.map(r => `<option value="${r.id}">${r.name}</option>`).join('');
        } catch(e){}
    }

    function renderUsers(users) {
        const list = document.getElementById('user-list');
        list.innerHTML = users.map(u => {
            const statusBadge = u.active == 1 || u.active === true || u.active === 'true' 
                ? '<span class="bg-emerald-100 text-emerald-700 px-2 py-1 rounded text-xs font-bold border border-emerald-200">Ativo</span>'
                : '<span class="bg-red-50 text-red-600 px-2 py-1 rounded text-xs font-bold border border-red-100">Inativo</span>';
            
            return `
            <tr class="hover:bg-slate-50 transition border-b border-slate-50 last:border-0 group">
                <td class="p-4 pl-6 font-bold text-slate-700 flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center text-slate-500 font-bold text-xs">
                        ${u.name.substring(0,2).toUpperCase()}
                    </div>
                    ${u.name}
                </td>
                <td class="p-4 text-slate-500">${u.email}</td>
                <td class="p-4"><span class="bg-blue-50 text-blue-700 px-2 py-1 rounded text-xs font-bold border border-blue-100">${u.role_name || '-'}</span></td>
                <td class="p-4 text-slate-500 text-xs">${u.customer_name || u.branch_name || '-'}</td>
                <td class="p-4 text-center">${statusBadge}</td>
                <td class="p-4 pr-6 text-right">
                    <button onclick="editUser(${u.id})" class="p-2 text-blue-600 hover:bg-blue-50 rounded transition"><i class="fas fa-edit"></i></button>
                    <button onclick="deleteUser(${u.id})" class="p-2 text-red-500 hover:bg-red-50 rounded transition ml-1"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;
        }).join('');
    }

    function filterUsers() {
        const term = document.getElementById('user-search').value.toLowerCase();
        const filtered = allUsers.filter(u => u.name.toLowerCase().includes(term) || u.email.toLowerCase().includes(term));
        renderUsers(filtered);
    }

    // --- CRUD ---
    function openModalUser() {
        document.getElementById('form-user').reset();
        document.getElementById('user-id').value = '';
        document.getElementById('modal-title').innerText = 'Novo Usuário';
        document.getElementById('modal-user').classList.remove('hidden');
        setTimeout(() => {
            document.getElementById('modal-user').classList.remove('opacity-0');
            document.getElementById('modal-user-content').classList.remove('scale-95');
            document.getElementById('modal-user-content').classList.add('scale-100');
        }, 10);
    }

    function editUser(id) {
        const u = allUsers.find(x => x.id == id);
        if(!u) return;
        openModalUser();
        document.getElementById('modal-title').innerText = 'Editar Usuário';
        document.getElementById('user-id').value = u.id;
        document.getElementById('user-name').value = u.name;
        document.getElementById('user-email').value = u.email;
        document.getElementById('user-role').value = u.role_id;
        document.getElementById('user-active').checked = (u.active == 1 || u.active === true || u.active === 'true');
    }

    async function saveUser(e) {
        e.preventDefault();
        const body = {
            id: document.getElementById('user-id').value,
            name: document.getElementById('user-name').value,
            email: document.getElementById('user-email').value,
            role_id: document.getElementById('user-role').value,
            password: document.getElementById('user-pass').value,
            active: document.getElementById('user-active').checked
        };

        try {
            const res = await fetch(`${API_URL}?action=save_user`, { method: 'POST', body: JSON.stringify(body) });
            const data = await res.json();
            if(data.error) throw new Error(data.error);
            
            closeModal('modal-user');
            loadUsers();
            alert('Salvo com sucesso!');
        } catch(err) { alert(err.message); }
    }

    async function deleteUser(id) {
        if(!confirm('Tem certeza?')) return;
        try {
            await fetch(`${API_URL}?action=delete_user`, { method: 'POST', body: JSON.stringify({id}) });
            loadUsers();
        } catch(e) { alert('Erro ao excluir'); }
    }

    function closeModal(id) {
        document.getElementById(id+'-content').classList.remove('scale-100');
        document.getElementById(id+'-content').classList.add('scale-95');
        document.getElementById(id).classList.add('opacity-0');
        setTimeout(() => document.getElementById(id).classList.add('hidden'), 300);
    }
</script>