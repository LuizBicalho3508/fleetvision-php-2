<?php
if (!isset($_SESSION['user_id'])) exit;
?>

<div class="h-full flex flex-col bg-slate-50 relative overflow-hidden">
    
    <div class="px-8 py-6 bg-white border-b border-slate-200 flex justify-between items-center shadow-sm z-20 shrink-0">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-wallet text-indigo-600"></i> Financeiro
            </h1>
            <p class="text-sm text-slate-500 mt-1">Gestão inteligente de cobranças.</p>
        </div>
        
        <div class="flex items-center gap-4">
            <div id="api-status" class="hidden px-3 py-1.5 rounded-full bg-red-50 text-red-600 text-xs font-bold border border-red-100 flex items-center gap-2 animate-pulse">
                <div class="w-2 h-2 rounded-full bg-red-500"></div> API Desconectada
            </div>
            
            <div id="balance-card" class="hidden group relative bg-slate-900 text-white pl-5 pr-6 py-2.5 rounded-xl shadow-lg shadow-slate-300 border border-slate-700 flex flex-col items-end min-w-[180px] cursor-default transition-transform hover:scale-[1.02]">
                <div class="absolute -left-4 -bottom-4 w-16 h-16 bg-white/5 rounded-full blur-xl group-hover:bg-white/10 transition"></div>
                <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider mb-0.5 z-10">Saldo Disponível</span>
                <div class="flex items-center gap-2 z-10">
                    <span class="text-xl font-bold text-emerald-400 tracking-tight" id="balance-value">R$ ...</span>
                    <i class="fas fa-sync-alt text-slate-600 text-xs hover:text-white cursor-pointer transition" title="Atualizar" onclick="loadBalance()"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto custom-scroll p-8">
        <div class="max-w-[1600px] mx-auto space-y-6">

            <div class="bg-white p-1.5 rounded-xl border border-slate-200 inline-flex shadow-sm mb-2 sticky top-0 z-30">
                <button onclick="switchTab('charges')" class="tab-btn px-5 py-2.5 rounded-lg text-sm font-bold transition-all duration-200 flex items-center gap-2 bg-indigo-50 text-indigo-700 shadow-sm" id="tab-charges">
                    <i class="fas fa-chart-pie"></i> Painel & Cobranças
                </button>
                <button onclick="switchTab('customers')" class="tab-btn px-5 py-2.5 rounded-lg text-sm font-medium text-slate-500 hover:text-slate-700 hover:bg-slate-50 transition-all duration-200 flex items-center gap-2" id="tab-customers">
                    <i class="fas fa-users"></i> Base de Clientes
                </button>
                <button onclick="switchTab('config')" class="tab-btn px-5 py-2.5 rounded-lg text-sm font-medium text-slate-500 hover:text-slate-700 hover:bg-slate-50 transition-all duration-200 flex items-center gap-2" id="tab-config">
                    <i class="fas fa-sliders-h"></i> Configurações
                </button>
            </div>

            <div id="view-charges" class="tab-content space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
                    <div onclick="openCardDetailModal('RECEIVED', 'Cobranças Recebidas')" class="cursor-pointer bg-white p-5 rounded-2xl border border-slate-100 shadow-sm hover:shadow-md hover:border-emerald-200 transition relative group overflow-hidden">
                        <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition text-emerald-600"><i class="fas fa-check-circle text-5xl"></i></div>
                        <p class="text-slate-500 text-xs font-bold uppercase tracking-wider">Recebidas</p>
                        <h3 class="text-3xl font-bold text-slate-800 mt-1" id="kpi-received-count">-</h3>
                        <div class="mt-3 flex items-center text-xs font-medium text-emerald-600 bg-emerald-50 w-fit px-2 py-1 rounded-md">Confirmadas</div>
                    </div>

                    <div onclick="openCardDetailModal('PENDING', 'Cobranças Pendentes')" class="cursor-pointer bg-white p-5 rounded-2xl border border-slate-100 shadow-sm hover:shadow-md hover:border-amber-200 transition relative group overflow-hidden">
                        <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition text-amber-500"><i class="fas fa-clock text-5xl"></i></div>
                        <p class="text-slate-500 text-xs font-bold uppercase tracking-wider">Pendentes</p>
                        <h3 class="text-3xl font-bold text-slate-800 mt-1" id="kpi-pending-count">-</h3>
                        <div class="mt-3 flex items-center text-xs font-medium text-amber-600 bg-amber-50 w-fit px-2 py-1 rounded-md">Aguardando</div>
                    </div>

                    <div onclick="openCardDetailModal('OVERDUE', 'Cobranças Vencidas')" class="cursor-pointer bg-white p-5 rounded-2xl border border-slate-100 shadow-sm hover:shadow-md hover:border-red-200 transition relative group overflow-hidden">
                        <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition text-red-500"><i class="fas fa-exclamation-triangle text-5xl"></i></div>
                        <p class="text-slate-500 text-xs font-bold uppercase tracking-wider">Vencidas</p>
                        <h3 class="text-3xl font-bold text-slate-800 mt-1" id="kpi-overdue-count">-</h3>
                        <div class="mt-3 flex items-center text-xs font-medium text-red-600 bg-red-50 w-fit px-2 py-1 rounded-md">Atrasadas</div>
                    </div>

                    <div class="bg-gradient-to-br from-indigo-600 to-indigo-800 text-white p-5 rounded-2xl shadow-lg shadow-indigo-200 relative overflow-hidden flex flex-col justify-between cursor-default">
                        <div>
                            <p class="text-indigo-200 text-xs font-bold uppercase tracking-wider">Total na Lista</p>
                            <h3 class="text-2xl font-bold mt-1" id="kpi-total-val">R$ 0,00</h3>
                        </div>
                        <p class="text-[10px] text-indigo-300 mt-2">Soma da página atual</p>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 flex flex-col min-h-[500px]">
                    <div class="p-5 border-b border-slate-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <div class="relative w-full md:w-96 group flex items-center">
                            <i class="fas fa-search absolute left-4 text-slate-400 z-10"></i>
                            <input type="text" id="charge-search" placeholder="Buscar cliente ou descrição..." 
                                   class="w-full pl-11 pr-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50 outline-none text-sm transition"
                                   oninput="debouncedSearchCharges()">
                        </div>
                        <div class="flex gap-3 w-full md:w-auto">
                            <button onclick="openModalCharge()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md shadow-indigo-100 transition flex items-center gap-2 active:scale-95">
                                <i class="fas fa-plus"></i> Nova Cobrança
                            </button>
                        </div>
                    </div>

                    <div class="flex-1 overflow-auto custom-scroll">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-slate-50/50 text-[11px] uppercase text-slate-500 font-bold border-b border-slate-100 sticky top-0 z-10 backdrop-blur-sm">
                                <tr>
                                    <th class="p-5 pl-6">Cliente / Descrição</th>
                                    <th class="p-5">Valor</th>
                                    <th class="p-5">Vencimento</th>
                                    <th class="p-5 text-center">Boleto / Fatura</th>
                                    <th class="p-5 text-center">Status</th>
                                    <th class="p-5 pr-6 text-right">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="list-charges" class="text-sm divide-y divide-slate-50 text-slate-600"></tbody>
                        </table>
                    </div>

                    <div class="p-4 border-t border-slate-100 bg-slate-50/50 flex justify-between items-center rounded-b-2xl">
                        <span class="text-xs text-slate-500 font-medium bg-white px-3 py-1 rounded border border-slate-200" id="page-info">Página 1</span>
                        <div class="flex gap-2">
                            <button onclick="prevPage()" id="btn-prev" class="px-4 py-2 rounded-lg border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 disabled:opacity-50 transition shadow-sm">Anterior</button>
                            <button onclick="nextPage()" id="btn-next" class="px-4 py-2 rounded-lg border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 disabled:opacity-50 transition shadow-sm">Próximo</button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="view-customers" class="tab-content hidden space-y-6">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 flex flex-col min-h-[500px]">
                    <div class="p-5 border-b border-slate-100 flex justify-between items-center gap-4">
                        <div class="relative w-full md:w-96 group flex items-center">
                            <i class="fas fa-search absolute left-4 text-slate-400 z-10"></i>
                            <input type="text" id="customer-search" placeholder="Nome, CPF ou CNPJ..." 
                                   class="w-full pl-11 pr-4 py-3 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:border-blue-500 focus:ring-4 focus:ring-blue-50 outline-none text-sm transition"
                                   oninput="debouncedSearchCustomers()">
                        </div>
                        <button onclick="openModalCustomer()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-xl font-bold text-sm shadow-md shadow-blue-100 transition flex items-center gap-2 active:scale-95">
                            <i class="fas fa-user-plus"></i> Novo Cliente
                        </button>
                    </div>
                    
                    <div class="flex-1 overflow-auto custom-scroll">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-slate-50/50 text-[11px] uppercase text-slate-500 font-bold border-b border-slate-100 sticky top-0 z-10 backdrop-blur-sm">
                                <tr>
                                    <th class="p-5 pl-6">Nome</th>
                                    <th class="p-5">Documento</th>
                                    <th class="p-5">Email</th>
                                    <th class="p-5 pr-6 text-right">ID Sistema</th>
                                </tr>
                            </thead>
                            <tbody id="list-customers" class="text-sm divide-y divide-slate-50 text-slate-600"></tbody>
                        </table>
                    </div>
                    <div class="p-4 border-t border-slate-100 bg-slate-50/50 flex justify-between items-center rounded-b-2xl">
                        <span class="text-xs text-slate-500 font-medium bg-white px-3 py-1 rounded border border-slate-200" id="cust-page-info">Página 1</span>
                        <div class="flex gap-2">
                            <button onclick="custPrevPage()" id="btn-cust-prev" class="px-4 py-2 rounded-lg border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 disabled:opacity-50 transition shadow-sm">Anterior</button>
                            <button onclick="custNextPage()" id="btn-cust-next" class="px-4 py-2 rounded-lg border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 disabled:opacity-50 transition shadow-sm">Próximo</button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="view-config" class="tab-content hidden">
                <div class="bg-white max-w-2xl rounded-2xl shadow-sm border border-slate-200 p-8">
                    <h3 class="text-xl font-bold text-slate-800 mb-6">Configuração Asaas</h3>
                    <div class="mb-8">
                        <label class="block text-xs font-bold text-slate-700 uppercase mb-2 ml-1">Chave de API</label>
                        <div class="relative group">
                            <input type="text" id="config-apikey" class="w-full pl-12 pr-4 py-4 rounded-xl border border-slate-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50 outline-none font-mono text-sm bg-white transition text-slate-700 shadow-sm">
                            <div class="absolute left-0 top-0 h-full w-12 flex items-center justify-center text-slate-400"><i class="fas fa-lock"></i></div>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button onclick="saveConfig()" class="bg-slate-800 hover:bg-slate-900 text-white px-8 py-3.5 rounded-xl font-bold transition shadow-lg active:scale-95">Salvar Chave</button>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<div id="modal-charge" class="fixed inset-0 bg-slate-900/60 hidden z-[100] flex items-center justify-center p-4 backdrop-blur-sm transition-opacity opacity-0">
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden transform scale-95 transition-all duration-300" id="modal-charge-content">
        <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="font-bold text-lg text-slate-800"><i class="fas fa-file-invoice text-indigo-600 mr-2"></i> Nova Cobrança</h3>
            <button onclick="closeModal('modal-charge')" class="w-8 h-8 rounded-full hover:bg-red-50 text-slate-400 hover:text-red-500 flex items-center justify-center transition"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6 space-y-5">
            
            <div class="relative">
                <label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">CLIENTE</label>
                <div class="relative">
                    <input type="text" id="charge-customer-search" placeholder="Digite para buscar..." class="w-full pl-10 p-3 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50 outline-none transition text-sm" autocomplete="off">
                    <i class="fas fa-search absolute left-3.5 top-3.5 text-slate-400 pointer-events-none"></i>
                    <input type="hidden" id="charge-customer-id">
                </div>
                <div id="customer-dropdown-list" class="absolute w-full bg-white border border-slate-200 rounded-xl mt-1 shadow-xl max-h-48 overflow-y-auto hidden z-50"></div>
            </div>

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">VALOR (R$)</label>
                    <div class="relative">
                        <input type="number" id="charge-value" step="0.01" class="w-full pl-10 p-3 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:border-indigo-500 outline-none transition font-bold text-slate-700" placeholder="0.00">
                        <span class="absolute left-3.5 top-3 text-slate-400 font-bold text-sm">R$</span>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">VENCIMENTO</label>
                    <input type="date" id="charge-duedate" class="w-full p-3 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:border-indigo-500 outline-none transition text-slate-600 cursor-pointer">
                </div>
            </div>
            
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">FORMA DE PAGAMENTO</label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="cursor-pointer">
                        <input type="radio" name="payType" value="BOLETO" class="peer hidden" checked>
                        <div class="p-3 rounded-xl border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:text-indigo-700 text-center transition hover:bg-slate-50 font-medium text-sm flex items-center justify-center gap-2">
                            <i class="fas fa-barcode"></i> Boleto
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="payType" value="PIX" class="peer hidden">
                        <div class="p-3 rounded-xl border border-slate-200 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:text-indigo-700 text-center transition hover:bg-slate-50 font-medium text-sm flex items-center justify-center gap-2">
                            <i class="brands fa-pix"></i> PIX
                        </div>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">DESCRIÇÃO</label>
                <input type="text" id="charge-desc" class="w-full p-3 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:border-indigo-500 outline-none transition text-sm" placeholder="Ex: Mensalidade Rastreamento">
            </div>

            <button onclick="createCharge()" class="w-full bg-indigo-600 text-white font-bold py-3.5 rounded-xl hover:bg-indigo-700 mt-2 shadow-lg shadow-indigo-100 transition active:scale-95">
                Emitir Cobrança
            </button>
        </div>
    </div>
</div>

<div id="modal-customer" class="fixed inset-0 bg-slate-900/60 hidden z-[100] flex items-center justify-center p-4 backdrop-blur-sm transition-opacity opacity-0">
    <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden transform scale-95 transition-all duration-300" id="modal-customer-content">
        <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="font-bold text-lg text-slate-800">Novo Cliente</h3>
            <button onclick="closeModal('modal-customer')" class="w-8 h-8 rounded-full hover:bg-red-50 text-slate-400 hover:text-red-500 transition flex items-center justify-center"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6 space-y-5">
            <div><label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">NOME</label><input type="text" id="cust-name" class="w-full p-3 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:border-blue-500 outline-none transition text-sm"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">CPF/CNPJ</label><input type="text" id="cust-cpf" class="w-full p-3 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:border-blue-500 outline-none transition text-sm"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1.5 ml-1">EMAIL</label><input type="email" id="cust-email" class="w-full p-3 border border-slate-200 rounded-xl bg-slate-50 focus:bg-white focus:border-blue-500 outline-none transition text-sm"></div>
            <button onclick="createCustomer()" class="w-full bg-blue-600 text-white font-bold py-3.5 rounded-xl hover:bg-blue-700 mt-2 shadow-lg shadow-blue-100 transition">Salvar</button>
        </div>
    </div>
</div>

<div id="modal-card-detail" class="fixed inset-0 bg-slate-900/60 hidden z-[110] flex items-center justify-center p-4 backdrop-blur-sm transition-opacity opacity-0">
    <div class="bg-white w-full max-w-4xl rounded-2xl shadow-2xl overflow-hidden transform scale-95 transition-all duration-300 flex flex-col max-h-[90vh]" id="modal-card-detail-content">
        <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50 shrink-0">
            <h3 class="font-bold text-lg text-slate-800" id="modal-card-title">Detalhes</h3>
            <button onclick="closeModal('modal-card-detail')" class="w-8 h-8 rounded-full hover:bg-red-50 text-slate-400 hover:text-red-500 transition flex items-center justify-center"><i class="fas fa-times"></i></button>
        </div>
        <div class="flex-1 overflow-auto custom-scroll p-0" id="modal-card-table-container"></div>
    </div>
</div>

<script>
    const API_URL = '../api_dados.php';
    const LIMIT = 10;
    
    let chargeOffset = 0; let chargeFilter = '';
    let custOffset = 0; let custFilter = '';
    const customerCache = {};

    // Debounce Helper
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), wait);
        };
    }

    const debouncedSearchCharges = debounce(() => { chargeFilter = document.getElementById('charge-search').value; chargeOffset = 0; loadCharges(); }, 600);
    const debouncedSearchCustomers = debounce(() => { custFilter = document.getElementById('customer-search').value; custOffset = 0; loadCustomers(); }, 600);

    document.addEventListener('DOMContentLoaded', async () => {
        const hasConfig = await checkConfig();
        if(hasConfig) { loadKPIs(); loadCharges(); }
        setupAutocomplete();
    });

    // --- AUTOCOMPLETE ---
    function setupAutocomplete() {
        const input = document.getElementById('charge-customer-search');
        const list = document.getElementById('customer-dropdown-list');
        const hiddenId = document.getElementById('charge-customer-id');
        let timeout = null;

        input.addEventListener('input', () => {
            clearTimeout(timeout);
            hiddenId.value = ''; 
            const val = input.value.trim();
            if(val.length < 2) { list.classList.add('hidden'); return; }

            timeout = setTimeout(async () => {
                try {
                    const res = await fetch(`${API_URL}?action=asaas_proxy&asaas_endpoint=/customers&name=${encodeURIComponent(val)}&limit=5`);
                    const data = await res.json();
                    if(!data.data || data.data.length === 0) list.innerHTML = '<div class="p-3 text-sm text-slate-400 text-center">Nenhum cliente</div>';
                    else {
                        list.innerHTML = data.data.map(c => `
                            <div onclick="selectCustomer('${c.id}', '${c.name}')" class="p-3 hover:bg-indigo-50 cursor-pointer border-b border-slate-50 last:border-0 transition">
                                <div class="font-bold text-slate-700 text-sm">${c.name}</div>
                                <div class="text-xs text-slate-400">${c.cpfCnpj || 'S/ Doc'}</div>
                            </div>
                        `).join('');
                    }
                    list.classList.remove('hidden');
                } catch(e) { console.error(e); }
            }, 400);
        });

        document.addEventListener('click', (e) => {
            if(!input.contains(e.target) && !list.contains(e.target)) list.classList.add('hidden');
        });
    }

    function selectCustomer(id, name) {
        document.getElementById('charge-customer-id').value = id;
        document.getElementById('charge-customer-search').value = name;
        document.getElementById('customer-dropdown-list').classList.add('hidden');
    }

    // --- CARREGAMENTO ---
    async function checkConfig() {
        try {
            const res = await fetch(`${API_URL}?action=asaas_get_config`);
            const data = await res.json();
            if (!data.has_token) { switchTab('config'); document.getElementById('api-status').classList.remove('hidden'); return false; }
            loadBalance(); return true;
        } catch (e) { switchTab('config'); return false; }
    }

    async function loadBalance() {
        try {
            const res = await fetch(`${API_URL}?action=asaas_proxy&asaas_endpoint=/finance/balance`);
            const data = await res.json();
            if(data.balance !== undefined) {
                document.getElementById('balance-value').innerText = `R$ ${data.balance.toLocaleString('pt-BR', {minimumFractionDigits: 2})}`;
                document.getElementById('balance-card').classList.remove('hidden');
            }
        } catch(e) {}
    }

    async function loadKPIs() {
        const getKpi = async (status) => {
            try {
                const res = await fetch(`${API_URL}?action=asaas_proxy&asaas_endpoint=/payments&status=${status}&limit=1`);
                return (await res.json()).totalCount || 0;
            } catch(e) { return 0; }
        };
        const [rec, pend, over] = await Promise.all([getKpi('RECEIVED'), getKpi('PENDING'), getKpi('OVERDUE')]);
        document.getElementById('kpi-received-count').innerText = rec;
        document.getElementById('kpi-pending-count').innerText = pend;
        document.getElementById('kpi-overdue-count').innerText = over;
    }

    async function loadCharges() {
        const list = document.getElementById('list-charges');
        list.innerHTML = Array(5).fill('').map(() => `<tr><td colspan="6" class="p-5"><div class="h-10 bg-slate-100 rounded-lg animate-pulse w-full"></div></td></tr>`).join('');
        
        let endpoint = `/payments?limit=${LIMIT}&offset=${chargeOffset}`;
        if(chargeFilter) endpoint += `&description=${encodeURIComponent(chargeFilter)}`;

        try {
            const res = await fetch(`${API_URL}?action=asaas_proxy&asaas_endpoint=${endpoint}`);
            const data = await res.json();
            if(!data.data || data.data.length === 0) { 
                list.innerHTML = '<tr><td colspan="6" class="p-10 text-center text-slate-400 italic">Nenhum registro.</td></tr>'; 
                document.getElementById('kpi-total-val').innerText = "R$ 0,00";
                return; 
            }

            let pageTotal = 0;
            const rows = data.data.map(c => {
                pageTotal += c.value;
                return renderChargeRow(c, true);
            }).join('');
            
            list.innerHTML = rows;
            document.getElementById('kpi-total-val').innerText = `R$ ${pageTotal.toLocaleString('pt-BR', {minimumFractionDigits: 2})}`;
            document.getElementById('page-info').innerText = `Página ${Math.floor(chargeOffset/LIMIT) + 1}`;
            document.getElementById('btn-prev').disabled = (chargeOffset === 0);
            document.getElementById('btn-next').disabled = (!data.hasMore);
        } catch(e) { list.innerHTML = `<tr><td colspan="6" class="p-5 text-red-500 text-center">Erro ao carregar.</td></tr>`; }
    }

    function renderChargeRow(c, showLink = false) {
        let st = { cls: 'bg-slate-100 text-slate-600', icon: 'fa-circle', label: c.status };
        if(c.status === 'RECEIVED' || c.status === 'CONFIRMED') st = { cls: 'bg-emerald-50 text-emerald-700 border-emerald-200', icon: 'fa-check', label: 'Recebido' };
        else if(c.status === 'OVERDUE') st = { cls: 'bg-red-50 text-red-700 border-red-200', icon: 'fa-exclamation-triangle', label: 'Vencido' };
        else if(c.status === 'PENDING') st = { cls: 'bg-amber-50 text-amber-700 border-amber-200', icon: 'fa-clock', label: 'Pendente' };

        // CORREÇÃO: Evita erro se o elemento não existir
        setTimeout(() => fetchCustomerName(c.customer, `row-${c.id}${showLink?'':'-modal'}`), 0);

        const linkColumn = showLink ? `<td class="p-5 text-center"><a href="${c.invoiceUrl}" target="_blank" class="text-xs font-bold text-indigo-600 bg-indigo-50 border border-indigo-100 px-3 py-1.5 rounded-full hover:bg-indigo-100 transition flex items-center justify-center gap-1 w-fit mx-auto"><i class="fas fa-barcode"></i> Ver Boleto</a></td>` : '';

        // ID único para evitar conflito entre tabela principal e modal
        const rowId = `row-${c.id}${showLink?'':'-modal'}`;

        return `
        <tr class="hover:bg-slate-50 border-b border-slate-50 transition group">
            <td class="p-5 pl-6">
                <div class="font-bold text-slate-700 text-sm" id="${rowId}-name">...</div>
                <div class="text-xs text-slate-400 mt-0.5">${c.description || 'Cobrança Avulsa'}</div>
            </td>
            <td class="p-5 font-mono text-sm text-slate-600 font-bold">R$ ${c.value.toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
            <td class="p-5 text-sm text-slate-500">${new Date(c.dueDate).toLocaleDateString('pt-BR')}</td>
            ${linkColumn}
            <td class="p-5 text-center"><span class="${st.cls} px-2.5 py-1 rounded-full border text-[10px] font-bold uppercase inline-flex items-center gap-1"><i class="fas ${st.icon}"></i> ${st.label}</span></td>
            <td class="p-5 pr-6 text-right"><a href="${c.invoiceUrl}" target="_blank" class="w-8 h-8 inline-flex items-center justify-center rounded-lg border border-slate-200 text-slate-400 hover:text-indigo-600 hover:bg-white transition"><i class="fas fa-external-link-alt"></i></a></td>
        </tr>`;
    }

    async function fetchCustomerName(id, elId) {
        // CORREÇÃO: Verifica se elemento existe antes de tentar alterar
        const el = document.getElementById(`${elId}-name`);
        if(!el) return;

        if(customerCache[id]) { el.innerText = customerCache[id]; return; }
        
        try {
            const res = await fetch(`${API_URL}?action=asaas_proxy&asaas_endpoint=/customers/${id}`);
            const data = await res.json();
            if(data.name) { customerCache[id] = data.name; if(document.getElementById(`${elId}-name`)) document.getElementById(`${elId}-name`).innerText = data.name; }
        } catch(e){}
    }

    async function openCardDetailModal(status, title) {
        const modal = document.getElementById('modal-card-detail');
        document.getElementById('modal-card-title').innerText = title;
        const container = document.getElementById('modal-card-table-container');
        container.innerHTML = '<div class="p-10 text-center"><i class="fas fa-spinner fa-spin"></i></div>';
        
        modal.classList.remove('hidden');
        setTimeout(() => { modal.classList.remove('opacity-0'); document.getElementById('modal-card-detail-content').classList.add('scale-100'); }, 10);

        try {
            const res = await fetch(`${API_URL}?action=asaas_proxy&asaas_endpoint=/payments&status=${status}&limit=50`);
            const data = await res.json();
            if(!data.data || data.data.length === 0) { container.innerHTML = '<div class="p-10 text-center text-slate-500">Vazio.</div>'; return; }
            
            container.innerHTML = `
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-50 text-[11px] font-bold text-slate-500 uppercase sticky top-0"><tr><th class="p-4 pl-6">Cliente</th><th class="p-4">Valor</th><th class="p-4">Vencimento</th><th class="p-4 text-center">Status</th><th class="p-4 pr-6 text-right">Ação</th></tr></thead>
                    <tbody class="text-sm divide-y divide-slate-50">${data.data.map(c => renderChargeRow(c, false)).join('')}</tbody>
                </table>`;
        } catch(e) {}
    }

    async function loadCustomers() {
        const list = document.getElementById('list-customers');
        list.innerHTML = Array(5).fill('').map(() => `<tr><td colspan="4" class="p-5"><div class="h-10 bg-slate-100 rounded-lg animate-pulse"></div></td></tr>`).join('');
        
        let endpoint = `/customers?limit=${LIMIT}&offset=${custOffset}`;
        if(custFilter) endpoint += `&name=${encodeURIComponent(custFilter)}`; 

        try {
            const res = await fetch(`${API_URL}?action=asaas_proxy&asaas_endpoint=${endpoint}`);
            const data = await res.json();
            if(!data.data || data.data.length === 0) { list.innerHTML = '<tr><td colspan="4" class="p-10 text-center text-slate-400 italic">Vazio.</td></tr>'; return; }

            list.innerHTML = data.data.map(c => {
                customerCache[c.id] = c.name;
                return `
                <tr class="hover:bg-slate-50 border-b border-slate-50">
                    <td class="p-5 pl-6 font-bold text-slate-700">${c.name}</td>
                    <td class="p-5 text-slate-500 font-mono text-xs">${c.cpfCnpj || '-'}</td>
                    <td class="p-5 text-slate-500 text-sm">${c.email || '-'}</td>
                    <td class="p-5 pr-6 text-right text-[10px] font-mono text-slate-400">${c.id}</td>
                </tr>`;
            }).join('');
            
            document.getElementById('cust-page-info').innerText = `Página ${Math.floor(custOffset/LIMIT) + 1}`;
            document.getElementById('btn-cust-prev').disabled = (custOffset === 0);
            document.getElementById('btn-cust-next').disabled = (!data.hasMore);
        } catch(e) {}
    }

    // --- CONTROLES CRUD ---
    async function createCharge() {
        const customerId = document.getElementById('charge-customer-id').value;
        if(!customerId) return alert('Selecione um cliente.');
        
        const body = {
            customer: customerId,
            billingType: document.querySelector('input[name="payType"]:checked').value,
            value: parseFloat(document.getElementById('charge-value').value),
            dueDate: document.getElementById('charge-duedate').value,
            description: document.getElementById('charge-desc').value
        };
        try {
            const res = await fetch(`${API_URL}?action=asaas_proxy&asaas_endpoint=/payments`, { method: 'POST', body: JSON.stringify(body) });
            if((await res.json()).id) { alert('Sucesso!'); closeModal('modal-charge'); loadCharges(); } else alert('Erro.');
        } catch(e) { alert('Erro.'); }
    }

    async function createCustomer() {
        const body = { name: document.getElementById('cust-name').value, cpfCnpj: document.getElementById('cust-cpf').value, email: document.getElementById('cust-email').value };
        try {
            const res = await fetch(`${API_URL}?action=asaas_proxy&asaas_endpoint=/customers`, { method: 'POST', body: JSON.stringify(body) });
            if((await res.json()).id) { alert('Sucesso!'); closeModal('modal-customer'); loadCustomers(); } else alert('Erro.');
        } catch(e) {}
    }

    async function saveConfig() {
        const key = document.getElementById('config-apikey').value;
        if(!key) return alert('Informe a chave.');
        try {
            await fetch(`${API_URL}?action=asaas_save_config`, { method: 'POST', body: JSON.stringify({ apiKey: key }) });
            alert('Salvo!'); location.reload();
        } catch(e) {}
    }

    // --- PAGINAÇÃO ---
    function nextPage() { chargeOffset += LIMIT; loadCharges(); }
    function prevPage() { if(chargeOffset >= LIMIT) { chargeOffset -= LIMIT; loadCharges(); } }
    function custNextPage() { custOffset += LIMIT; loadCustomers(); }
    function custPrevPage() { if(custOffset >= LIMIT) { custOffset -= LIMIT; loadCustomers(); } }

    function switchTab(tab) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.tab-btn').forEach(el => { el.classList.remove('bg-indigo-50', 'text-indigo-700', 'shadow-sm'); el.classList.add('text-slate-500', 'hover:bg-slate-50'); });
        document.getElementById(`view-${tab}`).classList.remove('hidden');
        document.getElementById(`tab-${tab}`).classList.add('bg-indigo-50', 'text-indigo-700', 'shadow-sm');
        document.getElementById(`tab-${tab}`).classList.remove('text-slate-500', 'hover:bg-slate-50');
        if(tab === 'customers') loadCustomers();
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        modal.classList.remove('hidden');
        setTimeout(() => { modal.classList.remove('opacity-0'); document.getElementById(id + '-content').classList.remove('scale-95'); document.getElementById(id + '-content').classList.add('scale-100'); }, 10);
    }
    function openModalCharge() { openModal('modal-charge'); }
    function openModalCustomer() { openModal('modal-customer'); }
    function closeModal(id) {
        document.getElementById(id+'-content').classList.remove('scale-100'); document.getElementById(id+'-content').classList.add('scale-95');
        document.getElementById(id).classList.add('opacity-0'); setTimeout(() => document.getElementById(id).classList.add('hidden'), 300);
    }
</script>