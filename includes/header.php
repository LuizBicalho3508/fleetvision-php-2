<?php
// includes/header.php
if (count(get_included_files()) == 1) exit('Acesso direto não permitido');

// 1. Recupera dados da sessão e configuração com falback seguro
$pageTitle = ucfirst(str_replace('_', ' ', $page ?? 'Dashboard'));
$userName = $_SESSION['user_name'] ?? 'Usuário';
$userEmail = $_SESSION['user_email'] ?? 'email@sistema.com';
$userRoleLabel = $_SESSION['user_role'] ?? 'User';
$userAvatar = $_SESSION['user_avatar'] ?? null;
$userInitials = strtoupper(substr($userName, 0, 2));

// Define o Slug Corretamente (Tenta da variável global $slug, depois da sessão, depois 'admin')
$tenantSlug = $slug ?? ($_SESSION['tenant_slug'] ?? 'admin');

// Nome da filial
$branchName = 'Matriz'; 
?>

<header class="h-16 bg-white shadow-sm flex items-center justify-between px-6 z-40 border-b border-gray-200 flex-shrink-0 relative">
    
    <div class="flex items-center gap-4">
        <button onclick="toggleSidebarLocal()" 
                class="w-10 h-10 flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition focus:outline-none focus:ring-2 focus:ring-slate-200">
            <i class="fas fa-bars text-lg"></i>
        </button>

        <h2 class="text-lg font-bold text-slate-700 capitalize flex items-center gap-2">
            <?php echo $pageTitle; ?>
        </h2>
        
        <div class="hidden md:flex items-center text-xs font-medium text-slate-500 bg-slate-50 px-3 py-1.5 rounded-full border border-slate-200 shadow-sm">
            <i class="fas fa-building mr-1.5 text-slate-400"></i> 
            <?php echo htmlspecialchars($branchName); ?>
        </div>
    </div>

    <div class="flex items-center gap-3">
        
        <button onclick="toggleFullScreenLocal()" 
                class="w-9 h-9 flex items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition hidden md:flex" title="Tela Cheia">
            <i class="fas fa-expand"></i>
        </button>

        <div class="relative" id="user-menu-container">
            <button id="user-menu-btn" 
                    class="flex items-center gap-3 pl-1 pr-2 py-1 rounded-full hover:bg-slate-50 transition border border-transparent hover:border-slate-100 focus:outline-none">
                
                <?php if($userAvatar): ?>
                    <img src="<?php echo APP_URL . '/' . htmlspecialchars($userAvatar); ?>" class="w-9 h-9 rounded-full object-cover border border-slate-200 shadow-sm" id="header-avatar-img">
                <?php else: ?>
                    <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm font-bold border border-indigo-200 shadow-sm">
                        <?php echo htmlspecialchars($userInitials); ?>
                    </div>
                <?php endif; ?>
                
                <div class="hidden md:block text-left">
                    <div class="text-sm font-bold text-slate-700 leading-tight max-w-[150px] truncate" id="header-user-name">
                        <?php echo htmlspecialchars($userName); ?>
                    </div>
                    <div class="text-[10px] text-slate-400 uppercase font-bold tracking-wider leading-tight">
                        <?php echo htmlspecialchars($userRoleLabel); ?>
                    </div>
                </div>
                
                <i class="fas fa-chevron-down text-slate-300 text-xs ml-1 transition-transform duration-200" id="user-menu-arrow"></i>
            </button>

            <div id="user-dropdown" 
                 class="absolute right-0 top-full mt-2 w-64 bg-white rounded-xl shadow-2xl border border-gray-100 hidden transform origin-top-right transition-all z-50">
                
                <div class="p-4 border-b border-gray-50 bg-gray-50/50">
                    <p class="text-xs text-gray-500 font-medium">Logado como</p>
                    <p class="text-sm font-bold text-gray-800 truncate" title="<?php echo htmlspecialchars($userEmail); ?>">
                        <?php echo htmlspecialchars($userEmail); ?>
                    </p>
                </div>
                
                <div class="p-2">
                    <button onclick="openProfileModalLocal()" class="w-full text-left px-3 py-2.5 rounded-lg text-sm text-gray-600 hover:bg-indigo-50 hover:text-indigo-600 flex items-center gap-3 transition font-medium">
                        <div class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center">
                            <i class="far fa-user-circle"></i>
                        </div>
                        Meu Perfil
                    </button>
                    
                    <a href="#" onclick="logoutUserLocal(event)" class="w-full text-left px-3 py-2.5 rounded-lg text-sm text-red-500 hover:bg-red-50 flex items-center gap-3 transition font-medium mt-1">
                        <div class="w-8 h-8 rounded-lg bg-red-100 text-red-500 flex items-center justify-center">
                            <i class="fas fa-sign-out-alt"></i>
                        </div>
                        Sair do Sistema
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>

<div id="modal-profile" class="fixed inset-0 z-[60] hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity opacity-0" id="modal-backdrop"></div>

    <div class="fixed inset-0 z-10 overflow-y-auto">
        <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <div class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="modal-panel">
                
                <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-indigo-100 sm:mx-0 sm:h-10 sm:w-10">
                            <i class="fas fa-user-edit text-indigo-600"></i>
                        </div>
                        <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
                            <h3 class="text-base font-bold leading-6 text-slate-800" id="modal-title">Editar Meu Perfil</h3>
                            <div class="mt-4">
                                <form id="form-profile-update" class="space-y-4" enctype="multipart/form-data">
                                    
                                    <div class="flex flex-col items-center sm:items-start gap-3 mb-4">
                                        <label class="block text-sm font-medium text-slate-700">Foto de Perfil</label>
                                        <div class="flex items-center gap-4">
                                            <div class="relative w-16 h-16">
                                                <img id="preview-avatar-modal" 
                                                     src="<?php echo $userAvatar ? APP_URL . '/' . $userAvatar : 'https://ui-avatars.com/api/?name='.urlencode($userName).'&background=e0e7ff&color=4f46e5'; ?>" 
                                                     class="w-16 h-16 rounded-full object-cover border-2 border-indigo-100 shadow-sm">
                                                <label for="avatar-input" class="absolute bottom-0 right-0 bg-white text-indigo-600 rounded-full w-6 h-6 flex items-center justify-center shadow-md border border-gray-200 cursor-pointer hover:bg-indigo-50">
                                                    <i class="fas fa-camera text-[10px]"></i>
                                                </label>
                                            </div>
                                            <input type="file" id="avatar-input" name="avatar" class="hidden" accept="image/*" onchange="previewImageLocal(this)">
                                            <div class="text-xs text-slate-400">
                                                JPG, PNG ou WEBP.<br>Máx 5MB.
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-slate-700">Nome Completo</label>
                                        <input type="text" name="name" value="<?php echo htmlspecialchars($userName); ?>" required
                                               class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700">Nova Senha <span class="text-gray-400 font-normal">(Opcional)</span></label>
                                        <input type="password" name="password" placeholder="Deixe em branco para manter"
                                               class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
                                    </div>

                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-slate-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                    <button type="button" onclick="saveProfileLocal()" 
                            class="inline-flex w-full justify-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-bold text-white shadow-sm hover:bg-indigo-500 sm:ml-3 sm:w-auto transition-all">
                        Salvar Alterações
                    </button>
                    <button type="button" onclick="closeProfileModalLocal()" 
                            class="mt-3 inline-flex w-full justify-center rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 sm:mt-0 sm:w-auto transition-all">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Variáveis Globais Locais
const tenantSlug = '<?php echo $tenantSlug; ?>';

document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('user-menu-btn');
    const menu = document.getElementById('user-dropdown');
    const arrow = document.getElementById('user-menu-arrow');

    // Toggle Menu
    if(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            menu.classList.toggle('hidden');
            if(arrow) arrow.classList.toggle('rotate-180');
        });
    }

    // Fechar ao clicar fora
    document.addEventListener('click', function(e) {
        if (menu && !menu.classList.contains('hidden') && !btn.contains(e.target) && !menu.contains(e.target)) {
            menu.classList.add('hidden');
            if(arrow) arrow.classList.remove('rotate-180');
        }
    });
});

// --- FUNÇÕES DO MODAL ---
function openProfileModalLocal() {
    const modal = document.getElementById('modal-profile');
    const backdrop = document.getElementById('modal-backdrop');
    const panel = document.getElementById('modal-panel');
    const dropdown = document.getElementById('user-dropdown');
    
    // Esconde dropdown menu
    if(dropdown) dropdown.classList.add('hidden');

    if (modal) {
        modal.classList.remove('hidden');
        // Animação de entrada
        setTimeout(() => {
            backdrop.classList.remove('opacity-0');
            panel.classList.remove('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');
            panel.classList.add('opacity-100', 'translate-y-0', 'sm:scale-100');
        }, 10);
    }
}

function closeProfileModalLocal() {
    const modal = document.getElementById('modal-profile');
    const backdrop = document.getElementById('modal-backdrop');
    const panel = document.getElementById('modal-panel');

    if (modal) {
        // Animação de saída
        backdrop.classList.add('opacity-0');
        panel.classList.remove('opacity-100', 'translate-y-0', 'sm:scale-100');
        panel.classList.add('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');

        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300);
    }
}

function previewImageLocal(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('preview-avatar-modal').src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

async function saveProfileLocal() {
    const form = document.getElementById('form-profile-update');
    const formData = new FormData(form);
    const btn = document.querySelector('#modal-panel button.bg-indigo-600');
    const originalText = btn.innerText;

    try {
        btn.disabled = true;
        btn.innerText = 'Salvando...';

        // Usa a função global apiRequest se existir, senão usa fetch direto
        let data;
        if(typeof apiRequest === 'function') {
            data = await apiRequest('update_profile', formData, 'POST');
        } else {
            // Fallback caso main.js falhe
            const res = await fetch('/api/router.php?action=update_profile', {
                method: 'POST',
                body: formData
            });
            data = await res.json();
        }

        if (data.success) {
            // Atualiza UI sem reload
            const nameVal = form.querySelector('input[name="name"]').value;
            const headerName = document.getElementById('header-user-name');
            if(headerName) headerName.innerText = nameVal;
            
            // Se trocou imagem, atualiza no header
            if(formData.get('avatar').size > 0 && data.avatar_url) {
                const headerImg = document.getElementById('header-avatar-img');
                if(headerImg) headerImg.src = data.avatar_url;
            }

            // Mostra toast se disponível
            if(typeof showToast === 'function') showToast('Perfil atualizado!', 'success');
            else alert('Perfil atualizado com sucesso!');
            
            closeProfileModalLocal();
        } else {
            alert(data.message || 'Erro ao atualizar.');
        }
    } catch (err) {
        console.error(err);
        alert('Erro de conexão ao salvar perfil.');
    } finally {
        btn.disabled = false;
        btn.innerText = originalText;
    }
}

// --- FUNÇÃO DE LOGOUT CORRIGIDA ---
async function logoutUserLocal(e) {
    if(e) e.preventDefault();
    
    // URL de Login
    const loginUrl = '/' + (tenantSlug || 'admin') + '/login';

    try {
        // Tenta limpar sessão no servidor via API
        if(typeof apiRequest === 'function') {
            await apiRequest('logout', {}, 'POST');
        } else {
            await fetch('/api/router.php?action=logout');
        }
    } catch(err) {
        console.log('API Logout failed, forcing redirect.');
    }
    
    // Força o redirecionamento independente do resultado da API
    window.location.href = loginUrl;
}

// Helpers Toggle (Caso main.js não carregue)
function toggleSidebarLocal() {
    const sidebar = document.getElementById('main-sidebar');
    if(window.toggleSidebar) window.toggleSidebar();
    else if(sidebar) sidebar.classList.toggle('-translate-x-full');
}
function toggleFullScreenLocal() {
    if(document.fullscreenElement) document.exitFullscreen();
    else document.documentElement.requestFullscreen();
}
</script>