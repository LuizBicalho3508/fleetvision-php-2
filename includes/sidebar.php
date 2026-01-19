<?php
// Garante que as variáveis globais do index estejam disponíveis
global $slug, $page, $tenant;
$role = $_SESSION['user_role'] ?? 'user';
?>

<aside id="main-sidebar" class="w-64 bg-slate-900 text-white flex flex-col shadow-2xl z-40 flex-shrink-0 transition-all duration-300 ease-in-out overflow-hidden" style="background-color: var(--secondary);">
    
    <div class="h-16 flex items-center justify-center border-b border-white/10 px-4 flex-shrink-0">
        <?php if(!empty($tenant['logo_url'])): ?>
            <img src="<?php echo $tenant['logo_url']; ?>" class="max-h-10 object-contain transition-all duration-300">
        <?php else: ?>
            <h1 class="text-lg font-bold uppercase tracking-widest truncate"><?php echo htmlspecialchars($tenant['logo_text'] ?? 'FLEET'); ?></h1>
        <?php endif; ?>
    </div>

    <nav class="flex-1 overflow-y-auto py-2 overflow-x-hidden custom-scroll">
        
        <a href="/<?php echo $slug; ?>/dashboard" class="sidebar-link <?php echo $page=='dashboard'?'active':''; ?>">
            <i class="fas fa-chart-pie w-5 mr-2 text-center"></i> <span>Dashboard</span>
        </a>
        <a href="/<?php echo $slug; ?>/mapa" class="sidebar-link <?php echo $page=='mapa'?'active':''; ?>">
            <i class="fas fa-map w-5 mr-2 text-center"></i> <span>Mapa & Grid</span>
        </a>

        <div class="sidebar-header">Operacional</div>
        <a href="/<?php echo $slug; ?>/frota" class="sidebar-link <?php echo $page=='frota'?'active':''; ?>">
            <i class="fas fa-truck w-5 mr-2 text-center"></i> <span>Veículos</span>
        </a>
        <a href="/<?php echo $slug; ?>/motoristas" class="sidebar-link <?php echo $page=='motoristas'?'active':''; ?>">
            <i class="fas fa-id-card w-5 mr-2 text-center"></i> <span>Motoristas</span>
        </a>
        <a href="/<?php echo $slug; ?>/cercas" class="sidebar-link <?php echo $page=='cercas'?'active':''; ?>">
            <i class="fas fa-draw-polygon w-5 mr-2 text-center"></i> <span>Cercas Virtuais</span>
        </a>
        <a href="/<?php echo $slug; ?>/alertas" class="sidebar-link <?php echo $page=='alertas'?'active':''; ?>">
            <i class="fas fa-bell w-5 mr-2 text-center"></i> <span>Alertas</span>
        </a>
        <a href="/<?php echo $slug; ?>/historico" class="sidebar-link <?php echo $page=='historico'?'active':''; ?>">
            <i class="fas fa-history w-5 mr-2 text-center"></i> <span>Replay de Rota</span>
        </a>
        <a href="/<?php echo $slug; ?>/jornada" class="sidebar-link <?php echo $page=='jornada'?'active':''; ?>">
            <i class="fas fa-business-time w-5 mr-2 text-center"></i> <span>Jornada</span>
        </a>
        <a href="/<?php echo $slug; ?>/ranking_motoristas" class="sidebar-link <?php echo $page=='ranking_motoristas'?'active':''; ?>">
            <i class="fas fa-medal w-5 mr-2 text-center text-yellow-500"></i> <span>Ranking</span>
        </a>

        <div class="sidebar-header">Gestão</div>
        <a href="/<?php echo $slug; ?>/clientes" class="sidebar-link <?php echo $page=='clientes'?'active':''; ?>">
            <i class="fas fa-users w-5 mr-2 text-center"></i> <span>Clientes</span>
        </a>
        
        <a href="/<?php echo $slug; ?>/financeiro" class="sidebar-link <?php echo $page=='financeiro'?'active':''; ?>">
            <i class="fas fa-file-invoice-dollar w-5 mr-2 text-center"></i> <span>Financeiro (Asaas)</span>
        </a>

        <a href="/<?php echo $slug; ?>/relatorios" class="sidebar-link <?php echo $page=='relatorios'?'active':''; ?>">
            <i class="fas fa-file-alt w-5 mr-2 text-center"></i> <span>Relatórios</span>
        </a>

        <?php if($role == 'admin' || $role == 'superadmin'): ?>
        <div class="sidebar-header">Administração</div>
        
        <a href="/<?php echo $slug; ?>/usuarios" class="sidebar-link <?php echo $page=='usuarios'?'active':''; ?>">
            <i class="fas fa-users-cog w-5 mr-2 text-center"></i> <span>Usuários & Equipe</span>
        </a>
        <a href="/<?php echo $slug; ?>/perfis" class="sidebar-link <?php echo $page=='perfis'?'active':''; ?>">
            <i class="fas fa-user-shield w-5 mr-2 text-center"></i> <span>Perfis de Acesso</span>
        </a>
        <a href="/<?php echo $slug; ?>/filiais" class="sidebar-link <?php echo $page=='filiais'?'active':''; ?>">
            <i class="fas fa-code-branch w-5 mr-2 text-center"></i> <span>Filiais</span>
        </a>

        <div class="sidebar-header">Técnico</div>
        <a href="/<?php echo $slug; ?>/estoque" class="sidebar-link <?php echo $page=='estoque'?'active':''; ?>">
            <i class="fas fa-boxes w-5 mr-2 text-center"></i> <span>Estoque</span>
        </a>
        <a href="/<?php echo $slug; ?>/teste" class="sidebar-link <?php echo $page=='teste'?'active':''; ?>">
            <i class="fas fa-microchip w-5 mr-2 text-center"></i> <span>Teste Equip.</span>
        </a>
        <a href="/<?php echo $slug; ?>/icones" class="sidebar-link <?php echo $page=='icones'?'active':''; ?>">
            <i class="fas fa-icons w-5 mr-2 text-center"></i> <span>Ícones 3D</span>
        </a>
        <?php endif; ?>

        <?php if($role == 'superadmin'): ?>
        <div class="sidebar-header text-yellow-500">Super Admin</div>
        <a href="/<?php echo $slug; ?>/admin_server" class="sidebar-link <?php echo $page=="admin_server"?"active":""; ?>">
            <i class="fas fa-server w-5 mr-2 text-center"></i> <span>Saúde Servidor</span>
        </a>
        <a href="/<?php echo $slug; ?>/crm" class="sidebar-link <?php echo $page=='crm'?'active':''; ?>">
            <i class="fas fa-briefcase w-5 mr-2 text-center"></i> <span>CRM Master</span>
        </a>
        <a href="/<?php echo $slug; ?>/gestao" class="sidebar-link <?php echo $page=='gestao'?'active':''; ?>">
            <i class="fas fa-paint-brush w-5 mr-2 text-center"></i> <span>Personalização</span>
        </a>
        <a href="/<?php echo $slug; ?>/tenant_users" class="sidebar-link <?php echo $page=='tenant_users'?'active':''; ?>">
            <i class="fas fa-user-shield w-5 mr-2 text-center"></i> <span>Admins Globais</span>
        </a>
        <a href="/<?php echo $slug; ?>/api_docs" class="sidebar-link <?php echo $page=='api_docs'?'active':''; ?>">
            <i class="fas fa-code w-5 mr-2 text-center"></i> <span>API Docs</span>
        </a>
        <?php endif; ?>
    </nav>
</aside>