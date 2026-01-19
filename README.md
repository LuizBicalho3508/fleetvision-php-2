```markdown
# 🚛 FleetVision Pro - Sistema de Gestão de Frotas (SaaS) v2.0

O **FleetVision Pro** é uma plataforma robusta de rastreamento e gestão de frotas desenvolvida em PHP (Vanilla MVC), projetada para operar no modelo SaaS (Multi-tenant). O sistema integra-se nativamente com a API do **Traccar** e gateways de pagamento (**Asaas**).

---

## 🚀 Tecnologias Utilizadas

* **Backend:** PHP 8.1+ (Puro, sem frameworks pesados).
* **Frontend:** HTML5, JavaScript (Vanilla ES6+), TailwindCSS.
* **Banco de Dados:** PostgreSQL 13+.
* **Mapas:** Leaflet JS + OpenStreetMap.
* **Integrações:** Traccar API (Rastreamento), Asaas (Financeiro).
* **Arquitetura:** MVC Modulada (API Restful + Views isoladas).

---

## 📂 Estrutura de Diretórios (Atualizada)

```text
/var/www/fleetvision/
├── api/                    # Backend (Lógica de Negócios)
│   ├── controllers/        # Controladores (Auth, Dashboard, Device, etc.)
│   └── router.php          # Roteador Central da API
├── api.php                 # Proxy de Entrada da API (Raiz)
├── config/                 # Configurações Globais
│   ├── app.php             # Variáveis de Ambiente e URL
│   └── db.php              # Conexão Singleton (PDO)
├── pages/                  # Views (Frontend HTML/PHP)
│   ├── login.php           # Tela de Login
│   ├── dashboard_*.php     # Dashboards por nível de acesso
│   └── ...                 # Outras páginas do sistema
├── includes/               # Componentes Visuais (Header, Sidebar)
├── assets/                 # Arquivos Estáticos (CSS, JS, Imagens)
├── uploads/                # Uploads de Usuários (Logos, Avatares)
└── index.php               # Front Controller (Roteamento de Páginas)

```

---

## 🗄️ Banco de Dados (Schema Completo)

O sistema utiliza **PostgreSQL**. Abaixo está o script SQL completo para criar a estrutura, incluindo as tabelas do sistema SaaS e suas relações.

> **Nota:** As tabelas nativas do Traccar (`tc_devices`, `tc_positions`, `tc_events`) não estão listadas aqui, pois são gerenciadas pelo próprio Traccar, mas o sistema faz amarrações lógicas através do ID do dispositivo.

### 1. Tabela de Tenants (Clientes/Empresas)

Armazena as configurações de cada empresa que usa o sistema (White-label).

```sql
CREATE TABLE saas_tenants (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE, -- Identificador na URL (ex: /cliente-a)
    document VARCHAR(20),              -- CNPJ/CPF
    email VARCHAR(255),
    phone VARCHAR(50),
    
    -- Personalização White-label
    logo_url TEXT,
    background_url TEXT,               -- Imagem de fundo do login
    primary_color VARCHAR(7) DEFAULT '#3b82f6',
    secondary_color VARCHAR(7) DEFAULT '#1e293b',
    
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Índices para performance
CREATE INDEX idx_tenants_slug ON saas_tenants(slug);

```

### 2. Tabela de Perfis de Acesso (Roles)

Define o que cada grupo de usuários pode fazer dentro de um Tenant.

```sql
CREATE TABLE saas_roles (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    name VARCHAR(50) NOT NULL,
    permissions JSONB DEFAULT '[]', -- Lista de permissões: ['dashboard', 'frota_view', 'bloqueio']
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Amarração: Se o tenant for deletado, os perfis somem
    CONSTRAINT fk_roles_tenant 
        FOREIGN KEY (tenant_id) 
        REFERENCES saas_tenants (id) 
        ON DELETE CASCADE
);

```

### 3. Tabela de Usuários

Usuários do sistema, vinculados a um Tenant e um Perfil.

```sql
CREATE TABLE saas_users (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    role_id INTEGER, -- Pode ser NULL se for SuperAdmin
    
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL, -- Hash Bcrypt
    avatar_url TEXT,
    
    active BOOLEAN DEFAULT TRUE,
    is_superadmin BOOLEAN DEFAULT FALSE, -- Acesso global ao sistema
    
    last_login TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP, -- Soft Delete
    
    -- Amarrações
    CONSTRAINT fk_users_tenant 
        FOREIGN KEY (tenant_id) 
        REFERENCES saas_tenants (id) 
        ON DELETE CASCADE,
        
    CONSTRAINT fk_users_role 
        FOREIGN KEY (role_id) 
        REFERENCES saas_roles (id) 
        ON DELETE SET NULL,

    -- Garante e-mail único por Tenant (ou global, dependendo da regra de negócio)
    CONSTRAINT uq_email_tenant UNIQUE (email, tenant_id)
);

```

### 4. Tabela de Filiais (Opcional)

Sub-divisões dentro de um Tenant.

```sql
CREATE TABLE saas_branches (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    name VARCHAR(255) NOT NULL,
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_branches_tenant 
        FOREIGN KEY (tenant_id) 
        REFERENCES saas_tenants (id) 
        ON DELETE CASCADE
);

```

### 5. Tabela de Veículos (Extensão do Traccar)

Armazena dados adicionais que o Traccar não guarda nativamente (ex: Financeiro, Manutenção).

```sql
CREATE TABLE saas_vehicles (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    branch_id INTEGER,
    
    traccar_device_id INTEGER NOT NULL UNIQUE, -- Vínculo com tabela tc_devices do Traccar
    
    plate VARCHAR(20),
    model VARCHAR(100),
    brand VARCHAR(100),
    color VARCHAR(50),
    year INTEGER,
    renavam VARCHAR(50),
    
    status VARCHAR(20) DEFAULT 'active', -- active, maintenance, inactive
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_vehicles_tenant 
        FOREIGN KEY (tenant_id) 
        REFERENCES saas_tenants (id) 
        ON DELETE CASCADE
);

```

### 6. Configurações Financeiras (Asaas)

Credenciais de pagamento por Tenant.

```sql
CREATE TABLE saas_financial_config (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL UNIQUE,
    api_key TEXT NOT NULL,
    wallet_id VARCHAR(100),
    is_sandbox BOOLEAN DEFAULT TRUE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_fin_config_tenant 
        FOREIGN KEY (tenant_id) 
        REFERENCES saas_tenants (id) 
        ON DELETE CASCADE
);

```

### 7. Cache de Endereços (Geocoding)

Evita chamadas repetidas à API de mapas (Nominatim/Google) para economizar custos/limites.

```sql
CREATE TABLE saas_address_cache (
    id SERIAL PRIMARY KEY,
    lat DECIMAL(10, 8) NOT NULL,
    lon DECIMAL(11, 8) NOT NULL,
    address TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Garante unicidade da coordenada (com precisão ajustada)
    CONSTRAINT uq_lat_lon UNIQUE (lat, lon)
);

```

---

## 🛠️ Instalação e Configuração

1. **Requisitos:**
* Servidor Web (Apache/Nginx)
* PHP 8.1+ com extensões `pdo_pgsql`, `curl`, `json`.
* PostgreSQL.


2. **Configuração:**
Edite o arquivo `config/app.php`:
```php
define('APP_URL', '[https://seusite.com](https://seusite.com)');
define('DB_HOST', 'localhost');
define('DB_NAME', 'traccar');
define('DB_USER', 'postgres');
define('DB_PASS', 'senha');

```


3. **Permissões:**
Certifique-se de que a pasta `uploads/` tem permissão de escrita:
```bash
chmod -R 775 uploads/
chown -R www-data:www-data uploads/

```



---

## 🔄 Fluxo de Autenticação

1. Usuário acessa `/admin/login` (ou `/cliente/login`).
2. Frontend envia POST para `/api.php?action=login`.
3. `AuthController` verifica credenciais e status do Tenant.
4. Se sucesso, cria Sessão PHP segura e retorna JSON com redirecionamento.
5. `index.php` verifica a sessão e carrega a View correspondente em `pages/`.

---

© 2026 FleetVision Pro - Todos os direitos reservados.

```

```