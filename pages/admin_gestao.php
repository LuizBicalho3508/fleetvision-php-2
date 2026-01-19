<?php
if ($_SESSION['user_role'] != 'superadmin') exit('Acesso Negado');
$msg = '';

// --- PROCESSAR POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    function uploadFile($fileInputName, $prefix) {
        if (!empty($_FILES[$fileInputName]['name'])) {
            $ext = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'])) {
                $name = $prefix . "_" . time() . "." . $ext;
                $target = __DIR__ . "/uploads/" . $name;
                if (move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $target)) {
                    return "/uploads/" . $name;
                }
            }
        }
        return null;
    }

    if ($_POST['action'] == 'update_design') {
        $id = $_POST['id'];
        $logoUrl = uploadFile('logo_file', 'logo_'.$id) ?? $_POST['current_logo'];
        $bgUrl = uploadFile('bg_file', 'bg_'.$id) ?? $_POST['current_bg'];

        $sql = "UPDATE saas_tenants SET 
                name=?, logo_text=?, logo_url=?, 
                primary_color=?, secondary_color=?, 
                login_bg_url=?, login_message=?, login_btn_color=?, login_card_opacity=?,
                login_card_bg=?, login_text_color=?
                WHERE id=?";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['name'], $_POST['logo_text'], $logoUrl,
                $_POST['primary_color'], $_POST['secondary_color'],
                $bgUrl, $_POST['login_message'], $_POST['login_btn_color'], $_POST['opacity'],
                $_POST['login_card_bg'], $_POST['login_text_color'],
                $id
            ]);
            $msg = "<div class='bg-green-100 border border-green-400 text-green-700 p-3 rounded mb-4'>Design atualizado com sucesso!</div>";
        } catch(Exception $e) {
            $msg = "<div class='bg-red-100 border border-red-400 text-red-700 p-3 rounded mb-4'>Erro: ".$e->getMessage()."</div>";
        }
    }
}

$empresas = $pdo->query("SELECT * FROM saas_tenants ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="p-8 bg-gray-50 min-h-screen">
    <div class="flex justify-between items-center mb-8">
        <div>
            <h2 class="text-3xl font-bold text-gray-800">Personalização Avançada</h2>
            <p class="text-gray-500">Configure a identidade visual completa de cada cliente.</p>
        </div>
    </div>

    <?php echo $msg; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach($empresas as $emp): ?>
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition">
            <div class="h-32 bg-gray-200 relative bg-cover bg-center" style="background-image: url('<?php echo $emp['login_bg_url'] ?: 'https://via.placeholder.com/400x150?text=Sem+Banner'; ?>');">
                <div class="absolute inset-0 bg-black/40"></div>
                <div class="absolute bottom-4 left-4 flex items-center gap-3">
                    <div class="w-12 h-12 bg-white rounded-lg p-1 shadow-lg flex items-center justify-center">
                        <?php if($emp['logo_url']): ?>
                            <img src="<?php echo $emp['logo_url']; ?>" class="max-w-full max-h-full">
                        <?php else: ?>
                            <span class="font-bold text-xs"><?php echo substr($emp['logo_text'],0,3); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-white">
                        <h3 class="font-bold text-lg leading-tight"><?php echo $emp['name']; ?></h3>
                    </div>
                </div>
            </div>
            <div class="p-4">
                <button onclick='openEditor(<?php echo json_encode($emp); ?>)' class="w-full py-2 bg-blue-50 text-blue-600 font-bold rounded-lg hover:bg-blue-100 transition">
                    <i class="fas fa-paint-brush mr-2"></i> Editar Cores & Imagens
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="editor-modal" class="fixed inset-0 bg-black/60 hidden z-50 backdrop-blur-sm flex justify-end">
    <div class="w-full max-w-2xl bg-white h-full shadow-2xl overflow-y-auto transform transition-transform translate-x-0">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_design">
            <input type="hidden" name="id" id="ed-id">
            <input type="hidden" name="current_logo" id="ed-current-logo">
            <input type="hidden" name="current_bg" id="ed-current-bg">

            <div class="px-8 py-6 border-b bg-gray-50 flex justify-between items-center sticky top-0 z-10">
                <h3 class="text-xl font-bold text-gray-800">Editando: <span id="ed-title" class="text-blue-600"></span></h3>
                <div class="flex gap-3">
                    <button type="button" onclick="closeEditor()" class="px-4 py-2 text-gray-500 hover:text-gray-800">Cancelar</button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg font-bold shadow hover:bg-blue-700">Salvar</button>
                </div>
            </div>

            <div class="p-8 space-y-8">
                
                <section>
                    <h4 class="section-title">Identidade Visual</h4>
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="label-input">Nome</label>
                            <input type="text" name="name" id="ed-name" class="input-field" required>
                        </div>
                        <div>
                            <label class="label-input">Texto Logo</label>
                            <input type="text" name="logo_text" id="ed-logo-text" class="input-field" required>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="label-input">Logotipo</label>
                        <input type="file" name="logo_file" class="text-sm text-gray-500 mt-1">
                    </div>
                </section>

                <section>
                    <h4 class="section-title">Cores do Painel</h4>
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="label-input">Cor Primária</label>
                            <input type="color" name="primary_color" id="ed-primary" class="color-picker">
                        </div>
                        <div>
                            <label class="label-input">Cor Sidebar</label>
                            <input type="color" name="secondary_color" id="ed-secondary" class="color-picker">
                        </div>
                    </div>
                </section>

                <section class="bg-blue-50 p-6 rounded-xl border border-blue-100">
                    <h4 class="section-title text-blue-600 border-blue-200">Tela de Login</h4>
                    
                    <div class="mb-4">
                        <label class="label-input">Banner de Fundo</label>
                        <input type="file" name="bg_file" class="text-sm text-gray-500 mt-1 w-full">
                    </div>

                    <div class="grid grid-cols-2 gap-6 mb-4">
                        <div>
                            <label class="label-input">Cor do Fundo do Card</label>
                            <input type="color" name="login_card_bg" id="ed-card-bg" class="color-picker">
                            <p class="text-[10px] text-gray-500 mt-1">Base para o efeito vidro.</p>
                        </div>
                        <div>
                            <label class="label-input">Cor do Texto</label>
                            <input type="color" name="login_text_color" id="ed-text-color" class="color-picker">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-6 mb-4">
                        <div>
                            <label class="label-input">Cor do Botão</label>
                            <input type="color" name="login_btn_color" id="ed-btn-color" class="color-picker">
                        </div>
                        <div>
                            <label class="label-input">Opacidade / Vidro</label>
                            <input type="range" name="opacity" id="ed-opacity" min="20" max="100" class="w-full mt-2 accent-blue-600">
                            <div class="text-right text-xs font-bold" id="opacity-val"></div>
                        </div>
                    </div>

                    <div>
                        <label class="label-input">Mensagem de Boas-Vindas</label>
                        <input type="text" name="login_message" id="ed-login-msg" class="input-field" placeholder="Ex: Gestão de Frotas">
                    </div>
                </section>

            </div>
        </form>
    </div>
</div>

<style>
    .section-title { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #9ca3af; border-bottom: 1px solid #e5e7eb; padding-bottom: 0.5rem; margin-bottom: 1rem; }
    .label-input { display: block; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #4b5563; margin-bottom: 0.25rem; }
    .input-field { width: 100%; border: 1px solid #d1d5db; padding: 0.5rem; border-radius: 0.5rem; outline: none; }
    .color-picker { width: 100%; height: 40px; padding: 0; border: 1px solid #d1d5db; border-radius: 0.5rem; cursor: pointer; }
</style>

<script>
function openEditor(data) {
    document.getElementById('ed-id').value = data.id;
    document.getElementById('ed-title').innerText = data.name;
    document.getElementById('ed-name').value = data.name;
    document.getElementById('ed-logo-text').value = data.logo_text;
    document.getElementById('ed-login-msg').value = data.login_message;
    
    // Cores
    document.getElementById('ed-primary').value = data.primary_color || '#3b82f6';
    document.getElementById('ed-secondary').value = data.secondary_color || '#111827';
    document.getElementById('ed-btn-color').value = data.login_btn_color || '#3b82f6';
    document.getElementById('ed-card-bg').value = data.login_card_bg || '#ffffff';
    document.getElementById('ed-text-color').value = data.login_text_color || '#374151';
    
    // Opacidade
    const op = data.login_card_opacity || 95;
    document.getElementById('ed-opacity').value = op;
    document.getElementById('opacity-val').innerText = op + '%';

    // Imagens
    document.getElementById('ed-current-logo').value = data.logo_url;
    document.getElementById('ed-current-bg').value = data.login_bg_url;

    document.getElementById('editor-modal').classList.remove('hidden');
}

function closeEditor() {
    document.getElementById('editor-modal').classList.add('hidden');
}

document.getElementById('ed-opacity').addEventListener('input', e => document.getElementById('opacity-val').innerText = e.target.value + '%');
</script>
