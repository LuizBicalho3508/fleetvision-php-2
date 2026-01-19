<?php
if (!isset($_SESSION['user_id'])) exit;
$isSuper = ($_SESSION['user_role'] == 'superadmin');

// --- FUNÇÃO AUXILIAR DE SANITIZAÇÃO ---
function sanitizeMoney($val) {
    if (empty($val)) return 0.00;
    $val = str_replace(['R$', ' ', '.'], '', $val);
    $val = str_replace(',', '.', $val);
    return (float)$val;
}

// --- FUNÇÃO PARA GERAR SLUG ---
function generateSlug($string) {
    $string = strtolower(trim($string));
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

// --- AÇÕES CRUD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $msg = null;
    $msgType = 'blue';

    try {
        // 1. CRIAÇÃO DE TENANT (NOVO)
        if ($action == 'create_tenant' && $isSuper) {
            $price = sanitizeMoney($_POST['unit_price']);
            $slug = generateSlug($_POST['name']);
            
            // Verifica duplicidade de slug
            $check = $pdo->prepare("SELECT id FROM saas_tenants WHERE slug = ?");
            $check->execute([$slug]);
            if($check->fetch()) {
                $slug .= '-' . time();
            }

            // Inserção com branches=1 (padrão)
            $sql = "INSERT INTO saas_tenants (
                name, slug, primary_color, secondary_color, 
                financial_status, unit_price, logo_text, branches
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['name'], 
                $slug, 
                $_POST['primary_color'] ?: '#3b82f6', 
                $_POST['secondary_color'] ?: '#1e293b', 
                $_POST['financial_status'], 
                $price,
                $_POST['name'], 
                '1'
            ]);
            
            $msg = "Nova Whitelabel criada com sucesso!";
            $msgType = "green";
        }

        // 2. CRIAÇÃO RÁPIDA DE USUÁRIO ADMIN (NOVO)
        if ($action == 'create_tenant_admin' && $isSuper) {
            $passHash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            // Verifica se email já existe
            $checkUser = $pdo->prepare("SELECT id FROM saas_users WHERE email = ?");
            $checkUser->execute([$_POST['email']]);
            
            if($checkUser->rowCount() > 0) {
                $msg = "Erro: Já existe um usuário com este e-mail.";
                $msgType = "red";
            } else {
                $sqlUser = "INSERT INTO saas_users (tenant_id, name, email, password, role) VALUES (?, ?, ?, ?, 'admin')";
                $stmtUser = $pdo->prepare($sqlUser);
                $stmtUser->execute([
                    $_POST['tenant_id'],
                    $_POST['name'],
                    $_POST['email'],
                    $passHash
                ]);
                $msg = "Usuário Admin criado com sucesso!";
                $msgType = "green";
            }
        }

        // 3. EDIÇÃO DE TENANT
        if ($action == 'update_tenant' && $isSuper) {
            $price = sanitizeMoney($_POST['unit_price']);
            
            $sql = "UPDATE saas_tenants SET name=?, slug=?, primary_color=?, secondary_color=?, financial_status=?, unit_price=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['name'], 
                $_POST['slug'], 
                $_POST['primary_color'], 
                $_POST['secondary_color'], 
                $_POST['financial_status'], 
                $price, 
                $_POST['id']
            ]);
            
            if ($stmt->rowCount() > 0) {
                $msg = "Parceiro atualizado! Preço: R$ " . number_format($price, 2, ',', '.');
                $msgType = "green";
            } else {
                $msg = "Nenhuma alteração detectada.";
                $msgType = "orange";
            }
        }

        // 4. EDIÇÃO DE LEAD
        if ($action == 'update_lead') {
            $val = sanitizeMoney($_POST['value']);
            $qty = (int)$_POST['expected_qty'];

            $sql = "UPDATE saas_crm_leads SET name=?, company=?, phone=?, email=?, value=?, expected_qty=?, status=?, notes=?, updated_at=NOW() WHERE id=?";
            $pdo->prepare($sql)->execute([
                $_POST['name'], 
                $_POST['company'], 
                $_POST['phone'], 
                $_POST['email'], 
                $val, 
                $qty,
                $_POST['status'], 
                $_POST['notes'], 
                $_POST['id']
            ]);
            $msg = "Lead salvo com sucesso!";
        }

        // 5. NOVO LEAD
        if ($action == 'create_lead') {
            $val = sanitizeMoney($_POST['value']);
            $qty = (int)$_POST['expected_qty'];

            $sql = "INSERT INTO saas_crm_leads (tenant_id, name, company, phone, email, value, expected_qty, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([
                $tenant['id'], 
                $_POST['name'], 
                $_POST['company'], 
                $_POST['phone'], 
                $_POST['email'], 
                $val, 
                $qty, 
                $_POST['status'], 
                $_POST['notes']
            ]);
            $msg = "Novo lead adicionado!";
            $msgType = "green";
        }

        // 6. DELETAR LEAD
        if ($action == 'delete_lead') {
            $pdo->prepare("DELETE FROM saas_crm_leads WHERE id=?")->execute([$_POST['id']]);
            $msg = "Registro removido.";
            $msgType = "red";
        }

    } catch (PDOException $e) {
        $msg = "Erro Banco: " . $e->getMessage();
        $msgType = "red";
    }

    // Exibe Toast Seguro
    if ($msg) {
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast(" . json_encode($msg) . ", '$msgType');
            });
        </script>";
    }
}

// --- BUSCA DE DADOS ---

// A. BUSCA WHITELABELS
$whitelabels = [];
$totalFaturamento = 0;
if ($isSuper) {
    // Busca tenants e conta veículos e usuários admins
    $sqlW = "SELECT t.*, 
            (SELECT COUNT(*) FROM saas_vehicles v WHERE v.tenant_id = t.id) as vehicle_count,
            (SELECT COUNT(*) FROM saas_users u WHERE u.tenant_id = t.id AND u.role = 'admin') as admin_count
            FROM saas_tenants t 
            ORDER BY t.id DESC";
    $whitelabels = $pdo->query($sqlW)->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($whitelabels as $w) {
        $totalFaturamento += ($w['unit_price'] * $w['vehicle_count']);
    }
}

// B. BUSCA LEADS
$leads = [];
if ($isSuper) {
    $leads = $pdo->query("SELECT * FROM saas_crm_leads ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT * FROM saas_crm_leads WHERE tenant_id = ? ORDER BY updated_at DESC");
    $stmt->execute([$tenant['id']]);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="flex flex-col h-screen bg-slate-50">
    
    <div class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center shadow-sm z-10">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-chart-line text-purple-600"></i> Gestão de Contratos
            </h2>
            <p class="text-sm text-slate-500">Controle financeiro e comercial.</p>
        </div>
        <div class="flex gap-2">
            <?php if($isSuper): ?>
            <button onclick="openTenantModal('create')" class="btn btn-secondary shadow-lg text-purple-600 border-purple-200 hover:bg-purple-50">
                <i class="fas fa-building"></i> Nova Whitelabel
            </button>
            <?php endif; ?>
            <button onclick="openLeadModal('create')" class="btn btn-primary shadow-lg">
                <i class="fas fa-plus"></i> Novo Lead
            </button>
        </div>
    </div>

    <div class="px-8 mt-6">
        <div class="flex border-b border-gray-300 gap-6">
            <?php if($isSuper): ?>
            <button onclick="switchTab('whitelabels')" id="tab-btn-whitelabels" class="pb-3 text-sm font-bold text-purple-600 border-b-2 border-purple-600 transition flex items-center gap-2">
                <i class="fas fa-building"></i> Whitelabels (Faturamento)
                <span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded-full text-xs ml-1"><?php echo count($whitelabels); ?></span>
            </button>
            <?php endif; ?>
            <button onclick="switchTab('leads')" id="tab-btn-leads" class="pb-3 text-sm font-bold text-gray-500 hover:text-blue-600 transition flex items-center gap-2">
                <i class="fas fa-filter"></i> Pipeline CRM
                <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full text-xs ml-1"><?php echo count($leads); ?></span>
            </button>
        </div>
    </div>

    <?php if($isSuper): ?>
    <div id="tab-whitelabels" class="flex-1 overflow-auto px-8 py-6">
        
        <div class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm flex justify-between items-center">
                <div><p class="text-xs text-gray-400 font-bold uppercase">Faturamento Mensal (Est.)</p><h3 class="text-2xl font-bold text-green-600">R$ <?php echo number_format($totalFaturamento, 2, ',', '.'); ?></h3></div>
                <div class="bg-green-100 p-3 rounded-full text-green-600"><i class="fas fa-money-bill-wave text-xl"></i></div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 text-slate-500 font-bold text-xs uppercase border-b">
                    <tr>
                        <th class="px-6 py-4">Empresa</th>
                        <th class="px-6 py-4 text-center">Veículos</th>
                        <th class="px-6 py-4 text-right">Valor/Veículo</th>
                        <th class="px-6 py-4 text-right">Total</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 text-right">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm text-slate-600">
                    <?php foreach($whitelabels as $w): 
                        $mensal = $w['vehicle_count'] * $w['unit_price'];
                        $wJson = htmlspecialchars(json_encode($w), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr class="hover:bg-purple-50 transition">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <div>
                                    <div class="font-bold text-slate-800"><?php echo htmlspecialchars($w['name']); ?></div>
                                    <div class="text-xs text-blue-500 font-mono">/<?php echo $w['slug']; ?></div>
                                </div>
                                <?php if($w['admin_count'] == 0): ?>
                                    <span class="bg-red-100 text-red-600 text-[10px] px-2 py-0.5 rounded-full font-bold" title="Sem admin criado!">!</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-center font-bold text-slate-700">
                            <?php echo $w['vehicle_count']; ?>
                        </td>
                        <td class="px-6 py-4 text-right font-mono text-gray-500">
                            R$ <?php echo number_format($w['unit_price'], 2, ',', '.'); ?>
                        </td>
                        <td class="px-6 py-4 text-right font-bold text-green-600 font-mono">
                            R$ <?php echo number_format($mensal, 2, ',', '.'); ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?php if($w['financial_status']=='active'): ?>
                                <span class="bg-green-100 text-green-700 px-2 py-1 rounded text-xs font-bold">Ativo</span>
                            <?php else: ?>
                                <span class="bg-red-100 text-red-700 px-2 py-1 rounded text-xs font-bold">Bloqueado</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <button onclick='openUserCreate(<?php echo $wJson; ?>)' class="btn btn-secondary py-1 px-3 text-xs border-blue-200 text-blue-600 hover:bg-blue-100 font-bold mr-1" title="Criar Usuário Admin">
                                <i class="fas fa-user-plus"></i>
                            </button>
                            <button onclick='openTenantEdit(<?php echo $wJson; ?>)' class="btn btn-secondary py-1 px-3 text-xs border-purple-200 text-purple-600 hover:bg-purple-100 font-bold">
                                <i class="fas fa-edit mr-1"></i> Gerenciar
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div id="tab-leads" class="flex-1 overflow-auto px-8 py-6 <?php echo $isSuper ? 'hidden' : ''; ?>">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 text-slate-500 font-bold text-xs uppercase border-b">
                    <tr>
                        <th class="px-6 py-4">Lead</th>
                        <th class="px-6 py-4">Fase</th>
                        <th class="px-6 py-4 text-center">Veículos (Est.)</th>
                        <th class="px-6 py-4 text-right">Valor Unit.</th>
                        <th class="px-6 py-4 text-right">Potencial</th>
                        <th class="px-6 py-4 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm text-slate-600">
                    <?php foreach($leads as $l): 
                        $totalLead = $l['value'] * $l['expected_qty'];
                        $lJson = htmlspecialchars(json_encode($l), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-6 py-4">
                            <div class="font-bold text-slate-800"><?php echo htmlspecialchars($l['company'] ?: $l['name']); ?></div>
                            <div class="text-xs text-gray-400"><?php echo htmlspecialchars($l['phone']); ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="bg-blue-100 text-blue-700 px-2 py-1 rounded text-xs font-bold uppercase"><?php echo $l['status']; ?></span>
                        </td>
                        <td class="px-6 py-4 text-center font-bold text-slate-600">
                            <?php echo $l['expected_qty']; ?>
                        </td>
                        <td class="px-6 py-4 text-right font-mono text-gray-500">
                            R$ <?php echo number_format($l['value'], 2, ',', '.'); ?>
                        </td>
                        <td class="px-6 py-4 text-right font-bold text-blue-600 font-mono">
                            R$ <?php echo number_format($totalLead, 2, ',', '.'); ?>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <button onclick='openLeadEdit(<?php echo $lJson; ?>)' class="btn btn-secondary py-1 px-2 text-xs"><i class="fas fa-pencil-alt"></i></button>
                            <form method="POST" class="inline" onsubmit="return confirm('Apagar lead?');">
                                <input type="hidden" name="action" value="delete_lead">
                                <input type="hidden" name="id" value="<?php echo $l['id']; ?>">
                                <button class="btn btn-secondary text-red-500 py-1 px-2 text-xs hover:bg-red-50"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<div id="modal-tenant" class="fixed inset-0 bg-black/60 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 animate-in fade-in zoom-in duration-200">
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h3 class="font-bold text-lg text-purple-700" id="ten-modal-title">Dados do Contrato</h3>
            <button onclick="document.getElementById('modal-tenant').classList.add('hidden')" class="text-gray-400 hover:text-red-500 text-xl">&times;</button>
        </div>
        <form method="POST" id="form-tenant">
            <input type="hidden" name="action" id="ten-action" value="update_tenant">
            <input type="hidden" name="id" id="ten-id">
            
            <div class="bg-purple-50 p-4 rounded-lg mb-6 border border-purple-100 shadow-inner">
                <div class="flex items-center justify-between mb-2">
                    <label class="text-xs font-bold text-purple-800 uppercase">Preço por Veículo (R$)</label>
                    <i class="fas fa-tag text-purple-300"></i>
                </div>
                <input type="text" name="unit_price" id="ten-price" class="w-full bg-white border border-purple-300 rounded px-3 py-2 text-2xl font-bold text-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500" placeholder="0,00">
                <p class="text-[10px] text-purple-500 mt-1 text-right">Use vírgula para centavos (ex: 9,90)</p>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="col-span-2">
                    <label class="block text-xs font-bold text-gray-500 mb-1">Nome Fantasia</label>
                    <input type="text" name="name" id="ten-name" class="input-std" required placeholder="Ex: Transportadora Expresso">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Status Financeiro</label>
                    <select name="financial_status" id="ten-status" class="input-std font-bold">
                        <option value="active" class="text-green-600">Ativo (Liberado)</option>
                        <option value="blocked" class="text-red-600">Bloqueado</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Slug (URL)</label>
                    <input type="text" name="slug" id="ten-slug" class="input-std bg-gray-100" readonly placeholder="Auto-gerado...">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Cor Principal</label>
                    <input type="color" name="primary_color" id="ten-color1" class="w-full h-10 rounded border cursor-pointer" value="#3b82f6">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Cor Secundária</label>
                    <input type="color" name="secondary_color" id="ten-color2" class="w-full h-10 rounded border cursor-pointer" value="#1e293b">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4 border-t">
                <button type="submit" class="btn btn-primary w-full shadow-lg">Salvar Contrato</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-user-create" class="fixed inset-0 bg-black/60 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm p-6">
        <h3 class="font-bold text-lg mb-2 text-blue-700">Criar Admin da Whitelabel</h3>
        <p class="text-xs text-gray-500 mb-4">Empresa: <span id="user-company-name" class="font-bold text-gray-800"></span></p>
        
        <form method="POST">
            <input type="hidden" name="action" value="create_tenant_admin">
            <input type="hidden" name="tenant_id" id="user-tenant-id">
            
            <label class="block text-xs font-bold text-gray-500 mb-1">Nome do Admin</label>
            <input type="text" name="name" class="input-std mb-3" required placeholder="Ex: Admin Transportadora">
            
            <label class="block text-xs font-bold text-gray-500 mb-1">E-mail de Login</label>
            <input type="email" name="email" class="input-std mb-3" required placeholder="admin@empresa.com">
            
            <label class="block text-xs font-bold text-gray-500 mb-1">Senha de Acesso</label>
            <input type="text" name="password" class="input-std mb-4" required placeholder="Senha inicial">

            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('modal-user-create').classList.add('hidden')" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary bg-blue-600 border-blue-600 hover:bg-blue-700">Criar Admin</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-lead" class="fixed inset-0 bg-black/60 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg p-6">
        <h3 class="font-bold text-lg mb-4" id="lead-modal-title">Novo Lead</h3>
        <form method="POST" id="form-lead">
            <input type="hidden" name="action" id="lead-action" value="create_lead">
            <input type="hidden" name="id" id="lead-id">
            
            <div class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <input type="text" name="company" id="lead-company" class="input-std" placeholder="Empresa">
                    <input type="text" name="name" id="lead-name" class="input-std" placeholder="Contato" required>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <input type="text" name="phone" id="lead-phone" class="input-std" placeholder="Telefone">
                    <input type="email" name="email" id="lead-email" class="input-std" placeholder="Email">
                </div>
                
                <div class="bg-blue-50 p-3 rounded border border-blue-100 grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[10px] font-bold text-blue-600 uppercase">Qtd Veículos</label>
                        <input type="number" name="expected_qty" id="lead-qty" class="input-std" placeholder="0">
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-blue-600 uppercase">Valor Unit. (R$)</label>
                        <input type="text" name="value" id="lead-val" class="input-std" placeholder="0,00">
                    </div>
                </div>

                <select name="status" id="lead-status" class="input-std">
                    <option value="new">Novo</option>
                    <option value="negotiation">Negociação</option>
                    <option value="won">Ganho</option>
                    <option value="lost">Perdido</option>
                </select>
                <textarea name="notes" id="lead-notes" class="input-std" rows="2" placeholder="Obs..."></textarea>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('modal-lead').classList.add('hidden')" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function switchTab(tab) {
        document.getElementById('tab-whitelabels')?.classList.add('hidden');
        document.getElementById('tab-leads').classList.add('hidden');
        document.getElementById('tab-btn-whitelabels')?.classList.replace('text-purple-600', 'text-gray-500');
        document.getElementById('tab-btn-whitelabels')?.classList.remove('border-b-2');
        document.getElementById('tab-btn-leads').classList.replace('text-blue-600', 'text-gray-500');
        document.getElementById('tab-btn-leads').classList.remove('border-b-2');

        document.getElementById('tab-'+tab).classList.remove('hidden');
        
        if(tab==='whitelabels') {
            const btn = document.getElementById('tab-btn-whitelabels');
            btn.classList.replace('text-gray-500', 'text-purple-600');
            btn.classList.add('border-b-2');
        } else {
            const btn = document.getElementById('tab-btn-leads');
            btn.classList.replace('text-gray-500', 'text-blue-600');
            btn.classList.add('border-b-2');
        }
    }

    // Tenant Modal Functions
    function openTenantModal(mode) {
        document.getElementById('modal-tenant').classList.remove('hidden');
        const form = document.getElementById('form-tenant');
        const title = document.getElementById('ten-modal-title');
        
        if (mode === 'create') {
            form.reset();
            document.getElementById('ten-action').value = 'create_tenant';
            title.innerText = 'Nova Whitelabel';
            document.getElementById('ten-slug').placeholder = 'Será gerado automaticamente...';
            document.getElementById('ten-color1').value = '#3b82f6';
            document.getElementById('ten-color2').value = '#1e293b';
        } else {
            document.getElementById('ten-action').value = 'update_tenant';
            title.innerText = 'Editar Contrato';
        }
    }

    function openTenantEdit(t) {
        openTenantModal('edit');
        document.getElementById('ten-id').value = t.id;
        document.getElementById('ten-name').value = t.name;
        document.getElementById('ten-slug').value = t.slug;
        document.getElementById('ten-status').value = t.financial_status;
        
        // Format Money BR (Ex: 10.50 -> 10,50)
        let price = parseFloat(t.unit_price || 0).toFixed(2).replace('.', ',');
        document.getElementById('ten-price').value = price;
        
        document.getElementById('ten-color1').value = t.primary_color;
        document.getElementById('ten-color2').value = t.secondary_color;
    }

    // New User Modal Function
    function openUserCreate(t) {
        document.getElementById('modal-user-create').classList.remove('hidden');
        document.getElementById('user-tenant-id').value = t.id;
        document.getElementById('user-company-name').innerText = t.name;
        // Limpar campos
        document.querySelector('input[name="name"]').value = '';
        document.querySelector('input[name="email"]').value = '';
        document.querySelector('input[name="password"]').value = '';
    }

    // Lead Edit
    function openLeadModal(mode) {
        document.getElementById('modal-lead').classList.remove('hidden');
        const form = document.getElementById('form-lead');
        
        document.getElementById('lead-action').value = mode == 'create' ? 'create_lead' : 'update_lead';
        document.getElementById('lead-modal-title').innerText = mode == 'create' ? 'Novo Lead' : 'Editar Lead';
        
        if(mode == 'create') form.reset();
    }

    function openLeadEdit(l) {
        openLeadModal('edit');
        document.getElementById('lead-id').value = l.id;
        document.getElementById('lead-name').value = l.name;
        document.getElementById('lead-company').value = l.company;
        document.getElementById('lead-phone').value = l.phone;
        document.getElementById('lead-email').value = l.email;
        
        // Format Money BR
        let val = parseFloat(l.value || 0).toFixed(2).replace('.', ',');
        document.getElementById('lead-val').value = val;
        
        document.getElementById('lead-qty').value = l.expected_qty;
        document.getElementById('lead-status').value = l.status;
        document.getElementById('lead-notes').value = l.notes;
    }
</script>