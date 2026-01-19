/**
 * FleetVision Pro - Main JavaScript
 * Gerencia UI global, comunicações com API e utilitários.
 */

const App = {
    // Configuração Base
    config: {
        // CORREÇÃO: Usa a URL definida no login.php (CONFIG.apiUrl) se existir.
        // Isso resolve o erro 404 quando o script roda em subpastas ou rotas amigáveis.
        apiUrl: (typeof CONFIG !== 'undefined' && CONFIG.apiUrl) ? CONFIG.apiUrl : 'api/router.php',
        sidebarKey: 'sidebar_state',
        debug: false // Mude para true para ver logs detalhados
    },

    // --- INICIALIZAÇÃO ---
    init: function() {
        this.bindEvents();
        this.restoreState();
        if (this.config.debug) console.log('App initialized', this.config);
    },

    // --- API WRAPPER (Fetch Centralizado) ---
    api: async function(action, params = {}, method = 'GET') {
        let url = `${this.config.apiUrl}?action=${action}`;
        let options = {
            method: method,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        if (method === 'GET') {
            // Converte objeto params em query string
            const query = new URLSearchParams(params).toString();
            if (query) url += `&${query}`;
        } else {
            // Se for FormData (upload), não define Content-Type (browser define boundary)
            // Se for objeto normal, envia como JSON
            if (params instanceof FormData) {
                options.body = params;
            } else {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(params);
            }
        }

        try {
            const response = await fetch(url, options);
            
            // Tratamento de erro HTTP (401, 403, 500)
            if (!response.ok) {
                if (response.status === 403 || response.status === 401) {
                    // Sessão expirada - Redireciona opcionalmente
                    // window.location.href = 'login.php';
                }
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.error || `Erro HTTP ${response.status}`);
            }

            const data = await response.json();
            return data;

        } catch (error) {
            console.error('API Error:', error);
            App.ui.toast(error.message, 'error');
            throw error; // Re-lança para quem chamou tratar se quiser
        }
    },

    // --- INTERFACE DE USUÁRIO (UI) ---
    ui: {
        // Controle da Sidebar
        toggleSidebar: function() {
            const sidebar = document.getElementById('main-sidebar');
            const wrapper = document.getElementById('main-wrapper'); // Se usar layout com wrapper
            
            // Lógica para Mobile e Desktop
            const isMobile = window.innerWidth < 768;
            
            if (isMobile) {
                // Mobile: geralmente usa classe de translate ou width
                sidebar.classList.toggle('-translate-x-full');
            } else {
                // Desktop: Recolher/Expandir
                if (sidebar.classList.contains('w-64')) {
                    sidebar.classList.replace('w-64', 'w-0'); // Ou w-20 para mini-sidebar
                    if(wrapper) wrapper.classList.remove('ml-64');
                    localStorage.setItem(App.config.sidebarKey, 'closed');
                } else {
                    sidebar.classList.replace('w-0', 'w-64');
                    if(wrapper) wrapper.classList.add('ml-64');
                    localStorage.setItem(App.config.sidebarKey, 'open');
                }
            }

            // Força o Leaflet a recalcular tamanho se houver mapa na tela
            setTimeout(() => {
                if (typeof map !== 'undefined' && map) map.invalidateSize();
            }, 300);
        },

        // Controle de Menu de Usuário (Dropdown)
        toggleUserMenu: function() {
            const menu = document.getElementById('user-dropdown');
            if (menu) menu.classList.toggle('hidden');
        },

        // Modal de Perfil
        openProfileModal: function() {
            const modal = document.getElementById('modal-profile');
            const menu = document.getElementById('user-dropdown');
            if (modal) {
                modal.classList.remove('hidden');
                // Animação simples
                setTimeout(() => {
                    const content = modal.querySelector('div'); // O card interno
                    if(content) content.classList.add('scale-100');
                }, 10);
            }
            if (menu) menu.classList.add('hidden');
        },

        closeModal: function(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            
            // Remove classes de animação se houver
            const content = modal.querySelector('div'); // O card interno (presumindo estrutura padrão)
            if (content && content.classList.contains('scale-100')) {
                content.classList.remove('scale-100');
                content.classList.add('scale-95');
            }
            
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 200);
        },

        // Preview de Imagem (Upload)
        previewImage: function(input, imgId = 'preview-avatar') {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.getElementById(imgId);
                    const divPreview = document.getElementById(imgId + '-div'); // Caso seja div com iniciais
                    
                    if (img) {
                        img.src = e.target.result;
                        img.classList.remove('hidden');
                        if (divPreview) divPreview.classList.add('hidden');
                    } else if (divPreview) {
                        // Se não tem img tag ainda, cria ou substitui
                        // Implementação simplificada: recarrega ou troca classe
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        },

        // Tela Cheia
        toggleFullScreen: function() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(err => {
                    console.log(`Erro ao ativar tela cheia: ${err.message}`);
                });
            } else {
                if (document.exitFullscreen) document.exitFullscreen();
            }
        },

        // Sistema de Notificações (Toasts)
        toast: function(message, type = 'info') {
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                document.body.appendChild(container);
            }

            const el = document.createElement('div');
            
            // Configuração de Cores/Ícones
            const configs = {
                success: { border: 'border-green-500', icon: 'fa-check-circle', color: 'text-green-600' },
                error:   { border: 'border-red-500',   icon: 'fa-times-circle', color: 'text-red-600' },
                warning: { border: 'border-yellow-500',icon: 'fa-exclamation-triangle', color: 'text-yellow-600' },
                info:    { border: 'border-blue-500',  icon: 'fa-info-circle',  color: 'text-blue-600' }
            };
            
            const cfg = configs[type] || configs.info;

            el.className = `toast ${cfg.border}`;
            el.innerHTML = `
                <i class="fas ${cfg.icon} ${cfg.color} text-lg"></i>
                <div class="flex-1 text-sm font-medium text-slate-700">${message}</div>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600 ml-2">
                    <i class="fas fa-times"></i>
                </button>
            `;

            container.appendChild(el);

            // Auto-remove após 5 segundos
            setTimeout(() => {
                el.style.animation = 'toastSlideOut 0.3s forwards';
                setTimeout(() => el.remove(), 300);
            }, 5000);
        }
    },

    // --- EVENTOS GLOBAIS ---
    bindEvents: function() {
        // Fecha dropdowns ao clicar fora
        window.onclick = function(event) {
            if (!event.target.closest('#user-menu-container')) {
                const dropdown = document.getElementById('user-dropdown');
                if (dropdown && !dropdown.classList.contains('hidden')) {
                    dropdown.classList.add('hidden');
                }
            }
        };
    },

    restoreState: function() {
        // Restaura sidebar
        const sidebar = document.getElementById('main-sidebar');
        const wrapper = document.getElementById('main-wrapper');
        const state = localStorage.getItem(this.config.sidebarKey);
        
        if (sidebar && state === 'closed' && window.innerWidth >= 768) {
            sidebar.classList.replace('w-64', 'w-0');
            if (wrapper) wrapper.classList.remove('ml-64');
        }
    }
};

// --- EXPORTAR FUNÇÕES GLOBAIS (Para compatibilidade com onclick no HTML) ---
window.toggleSidebar = () => App.ui.toggleSidebar();
window.toggleUserMenu = () => App.ui.toggleUserMenu();
window.openProfileModal = () => App.ui.openProfileModal();
window.closeModal = (id) => App.ui.closeModal(id);
window.toggleFullScreen = () => App.ui.toggleFullScreen();
window.previewImage = (input) => App.ui.previewImage(input);
window.showToast = (msg, type) => App.ui.toast(msg, type);

// Atalho para API request
window.apiRequest = (action, params, method) => App.api(action, params, method);

// Inicializa ao carregar
document.addEventListener('DOMContentLoaded', () => {
    App.init();
});