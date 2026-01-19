<div class="p-8 max-w-7xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Gestão de Usuários</h1>
            <p class="text-slate-500 text-sm">Gerencie o acesso da sua equipe.</p>
        </div>
        <button onclick="openUserModal()" class="btn btn-primary">
            <i class="fas fa-plus mr-2"></i> Novo Usuário
        </button>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                <tr>
                    <th class="p-4">Nome</th>
                    <th class="p-4">Email</th>
                    <th class="p-4">Perfil</th>
                    <th class="p-4 text-center">Status</th>
                    <th class="p-4 text-right">Ações</th>
                </tr>
            </thead>
            <tbody id="users-table-body" class="divide-y divide-slate-100">
                <tr><td colspan="5" class="p-8 text-center text-slate-400"><i class="fas fa-spinner fa-spin mr-2"></i>Carregando...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div id="modal-user" class="fixed inset-0 bg-black/60 hidden z-50 flex items-center justify-center backdrop-blur-sm p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6 animate-in zoom-in duration-200">
        <h3 class="text-lg font-bold mb-4" id="modal-title">Novo Usuário</h3>
        <form id="form-user" class="space-y-4">
            <input type="hidden" name="id" id="user-id">
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1">Nome</label>
                <input type="text" name="name" id="user-name" class="input-std" required>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1">Email</label>
                <input type="email" name="email" id="user-email" class="input-std" required>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1">Senha</label>
                <input type="password" name="password" id="user-pass" class="input-std" placeholder="Apenas para alterar">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1">Perfil</label>
                <select name="role_id" id="user-role" class="input-std">
                    <option value="">Selecione...</option>
                    </select>
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" name="active" id="user-active" class="rounded text-blue-600" checked>
                <label for="user-active" class="text-sm text-slate-600">Usuário Ativo</label>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="closeModal('modal-user')" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
// Lógica Frontend (Consome API)
document.addEventListener('DOMContentLoaded', () => {
    loadUsers();
    loadRoles(); // Carrega select de perfis
});

async function loadUsers() {
    try {
        // Chama a nova API (ManagementController)
        const users = await apiRequest('get_users'); 
        const tbody = document.getElementById('users-table-body');
        
        if (!users.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center">Nenhum usuário encontrado.</td></tr>';
            return;
        }

        tbody.innerHTML = users.map(u => `
            <tr class="hover:bg-slate-50 transition">
                <td class="p-4 font-medium text-slate-700">${u.name}</td>
                <td class="p-4">${u.email}</td>
                <td class="p-4"><span class="bg-blue-100 text-blue-700 px-2 py-1 rounded text-xs font-bold">${u.role_name || 'N/A'}</span></td>
                <td class="p-4 text-center">
                    ${u.active === 'true' || u.active === true 
                        ? '<span class="text-green-600 font-bold text-xs">Ativo</span>' 
                        : '<span class="text-red-500 font-bold text-xs">Inativo</span>'}
                </td>
                <td class="p-4 text-right">
                    <button onclick='editUser(${JSON.stringify(u)})' class="text-blue-600 hover:bg-blue-50 p-2 rounded"><i class="fas fa-edit"></i></button>
                    <button onclick="deleteUser(${u.id})" class="text-red-500 hover:bg-red-50 p-2 rounded"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `).join('');
    } catch (err) {
        console.error(err);
    }
}

async function loadRoles() {
    const roles = await apiRequest('get_profiles');
    const select = document.getElementById('user-role');
    select.innerHTML = '<option value="">Selecione...</option>' + 
        roles.map(r => `<option value="${r.id}">${r.name}</option>`).join('');
}

// Manipulação do Formulário
document.getElementById('form-user').addEventListener('submit', async function(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(this));
    data.active = document.getElementById('user-active').checked; // Checkbox fix

    const res = await apiRequest('save_user', data, 'POST');
    if (res.success) {
        showToast('Usuário salvo!', 'success');
        closeModal('modal-user');
        loadUsers();
    } else {
        showToast(res.error || 'Erro ao salvar', 'error');
    }
});

async function deleteUser(id) {
    if (!confirm('Tem certeza?')) return;
    const res = await apiRequest('delete_user', { id }, 'POST');
    if (res.success) {
        showToast('Excluído com sucesso', 'success');
        loadUsers();
    } else {
        showToast(res.error, 'error');
    }
}

// Helpers de UI
function openUserModal() {
    document.getElementById('form-user').reset();
    document.getElementById('user-id').value = '';
    document.getElementById('modal-title').innerText = 'Novo Usuário';
    document.getElementById('modal-user').classList.remove('hidden');
}

function editUser(u) {
    openUserModal();
    document.getElementById('modal-title').innerText = 'Editar Usuário';
    document.getElementById('user-id').value = u.id;
    document.getElementById('user-name').value = u.name;
    document.getElementById('user-email').value = u.email;
    document.getElementById('user-role').value = u.role_id;
    document.getElementById('user-active').checked = (u.active === 'true' || u.active === true);
}
</script>