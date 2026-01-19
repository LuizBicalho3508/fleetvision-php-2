<?php
if (!isset($_SESSION['user_id'])) exit;
if ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'superadmin') exit('Acesso Negado');

// --- SEGURANÇA: GARANTIR CONTEXTO ---
$currentTenantId = $tenant['id'] ?? $_SESSION['tenant_id'] ?? 0;
$isSuper = ($_SESSION['user_role'] == 'superadmin');

$uploadDir = __DIR__ . '/uploads/icons/';
$webPathBase = '/uploads/icons/';

// --- UPLOAD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['icon_file'])) {
    $name = $_POST['name'] ?? 'Ícone';
    $file = $_FILES['icon_file'];
    
    // Verifica se é global (apenas superadmin pode criar globais)
    $isGlobal = ($isSuper && isset($_POST['is_global']) && $_POST['is_global'] == '1');
    $targetTenant = $isGlobal ? null : $currentTenantId; // Null = Global

    $msg = '';
    $type = 'blue';

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['png', 'svg', 'gif', 'jpg', 'jpeg'];
    
    if (!in_array($ext, $allowed)) {
        $msg = "Formato inválido! Use PNG, SVG ou JPG.";
        $type = "red";
    } else {
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

        $filename = uniqid('icon_') . '.' . $ext;
        $destPath = $uploadDir . $filename;
        $dbUrl = $webPathBase . $filename; 
        
        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            try {
                $sql = "INSERT INTO saas_custom_icons (tenant_id, name, url, category) VALUES (?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([$targetTenant, $name, $dbUrl, 'custom']);
                
                $msg = $isGlobal ? "Ícone GLOBAL adicionado com sucesso!" : "Ícone enviado com sucesso!";
                $type = "green";
            } catch (PDOException $e) {
                if (file_exists($destPath)) unlink($destPath);
                $msg = "Erro SQL: " . $e->getMessage();
                $type = "red";
            }
        } else {
            $msg = "Erro de Permissão na pasta uploads.";
            $type = "red";
        }
    }

    if ($msg) {
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast(" . json_encode($msg) . ", '$type');
            });
        </script>";
    }
}

// --- DELETE ---
if (isset($_POST['action']) && $_POST['action'] == 'delete') {
    $msg = '';
    $type = 'blue';
    try {
        $id = $_POST['id'];
        
        // Verifica permissão de exclusão
        // Superadmin apaga tudo. Admin normal só apaga os seus (tenant_id não nulo e igual ao atual)
        if ($isSuper) {
            $stmt = $pdo->prepare("SELECT * FROM saas_custom_icons WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM saas_custom_icons WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $currentTenantId]);
        }
        
        $icon = $stmt->fetch();
        
        if ($icon) {
            $fileToDelete = __DIR__ . $icon['url'];
            if (file_exists($fileToDelete)) unlink($fileToDelete);
            
            $pdo->prepare("DELETE FROM saas_custom_icons WHERE id = ?")->execute([$id]);
            $msg = "Ícone removido.";
        } else {
            $msg = "Erro: Ícone não encontrado ou sem permissão.";
            $type = "red";
        }
    } catch (Exception $e) {
        $msg = "Erro ao deletar: " . $e->getMessage();
        $type = "red";
    }

    if ($msg) {
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast(" . json_encode($msg) . ", '$type');
            });
        </script>";
    }
}

// --- LISTAGEM (LOCAL + GLOBAL) ---
$icons = [];
try {
    // Busca ícones desta empresa OU globais (tenant_id IS NULL)
    $sql = "SELECT * FROM saas_custom_icons WHERE tenant_id = ? OR tenant_id IS NULL ORDER BY tenant_id ASC, id DESC"; // Globais aparecem primeiro ou por último dependendo da ordem desejada
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$currentTenantId]);
    $icons = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "<div class='text-xs text-red-400 p-2'>Erro na listagem: " . $e->getMessage() . "</div>";
}
?>

<div class="flex flex-col h-screen bg-slate-50">
    <div class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center shadow-sm z-10">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">Biblioteca de Ícones</h2>
            <p class="text-sm text-slate-500">Personalize a visualização da frota no mapa.</p>
        </div>
        <button onclick="openModal()" class="btn btn-primary shadow-lg hover:shadow-xl transition-transform transform hover:-translate-y-0.5">
            <i class="fas fa-cloud-upload-alt"></i> Enviar Novo Ícone
        </button>
    </div>

    <div class="flex-1 overflow-auto p-8">
        <?php if(empty($icons)): ?>
            <div class="flex flex-col items-center justify-center h-64 text-gray-400 border-2 border-dashed border-gray-300 rounded-xl bg-slate-100">
                <i class="fas fa-images text-4xl mb-4 opacity-50"></i>
                <p>Nenhum ícone personalizado.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-6">
                <?php foreach($icons as $icon): 
                    $isGlobalIcon = ($icon['tenant_id'] === null);
                    // Admin comum não pode deletar global
                    $canDelete = ($isSuper || !$isGlobalIcon);
                ?>
                <div class="group bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-md transition relative">
                    
                    <?php if($isGlobalIcon): ?>
                        <span class="absolute top-0 left-0 bg-blue-600 text-white text-[9px] font-bold px-2 py-1 z-10 rounded-br shadow-sm">GLOBAL</span>
                    <?php endif; ?>

                    <div class="h-32 bg-gray-100 flex items-center justify-center relative bg-[url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABGdBTUEAALGPC/xhBQAAAAlwSFlzAAAOwgAADsIBFShKgAAAABpJREFUOE9jYO/q+c+AAxhIT0//R4M0A4MzAJqqL9X4/p7QAAAAAElFTkSuQmCC')]">
                        <img src="<?php echo $icon['url']; ?>?v=<?php echo time(); ?>" class="h-16 w-16 object-contain">
                        
                        <?php if($canDelete): ?>
                        <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                            <form method="POST" onsubmit="return confirm('Excluir este ícone?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $icon['id']; ?>">
                                <button class="bg-white text-red-500 rounded-full w-8 h-8 flex items-center justify-center shadow-md hover:bg-red-50"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-3 border-t border-gray-100 text-center">
                        <h4 class="font-bold text-slate-700 text-sm truncate"><?php echo htmlspecialchars($icon['name']); ?></h4>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="modal-upload" class="fixed inset-0 bg-black/60 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="bg-slate-50 px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="font-bold text-lg text-slate-800">Adicionar Ícone</h3>
            <button onclick="document.getElementById('modal-upload').classList.add('hidden')" class="text-gray-400 hover:text-red-500 text-xl">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="p-6">
            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-500 mb-1 uppercase">Nome do Ícone</label>
                <input type="text" name="name" class="input-std" placeholder="Ex: Caminhão Baú" required>
            </div>
            
            <div class="mb-6">
                <label class="block text-xs font-bold text-gray-500 mb-2 uppercase">Arquivo de Imagem</label>
                <input name="icon_file" type="file" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" accept="image/*" required />
            </div>

            <?php if($isSuper): ?>
            <div class="mb-6 flex items-center gap-2 bg-blue-50 p-3 rounded border border-blue-100">
                <input type="checkbox" name="is_global" id="is_global" value="1" class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
                <label for="is_global" class="text-sm font-bold text-blue-800 cursor-pointer select-none">
                    Ícone Global? <span class="font-normal text-xs block text-blue-600">Visível para todas as empresas</span>
                </label>
            </div>
            <?php endif; ?>

            <div class="flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('modal-upload').classList.add('hidden')" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary px-6">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal() { document.getElementById('modal-upload').classList.remove('hidden'); }
</script>