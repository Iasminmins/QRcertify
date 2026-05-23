# Carteira Digital de Certificados — Versão PHP

Sistema de carteiras digitais para certificados industriais (NRs, ISO, CIPA) com QR Code, convertido para PHP puro + MySQL para rodar na Hostinger.

## 📋 O que tem dentro

- **Login multi-perfil** (Super Admin, Admin de empresa, Colaborador)
- **CRUD de funcionários** (cadastrar, editar, excluir, filtrar)
- **CRUD de certificados** (com geração automática de QR Code)
- **Página pública de validação** via QR Code (qualquer pessoa pode validar)
- **Dashboard** do admin com estatísticas de certificados
- **Página do colaborador** ("Meus Certificados")
- **Painel do Super Admin** com gestão de empresas (multi-tenant)
- **Atualização automática** do status dos certificados (válido / expirando / expirado)

## 🛠️ Tecnologias

- **PHP 7.4+** (compatível até PHP 8.x)
- **MySQL / MariaDB** (Hostinger)
- **Tailwind CSS** via CDN (sem build, funciona em qualquer hospedagem)
- **API gratuita do qrserver.com** para gerar imagens de QR Code
- **PDO** para acesso ao banco (com prepared statements — protege contra SQL Injection)
- **Sessões PHP** + CSRF token para segurança

## 📁 Estrutura

```
site-carteira-php/
├── config/
│   ├── config.php          # Configurações gerais
│   └── database.php        # ⚠️ EDITE com seu banco da Hostinger
├── includes/
│   ├── auth.php            # Funções de login/sessão
│   ├── functions.php       # Helpers (formatação, CSRF, etc.)
│   ├── header.php          # Header HTML comum
│   ├── footer.php          # Footer HTML comum
│   └── sidebar.php         # Menu lateral
├── admin/                  # Páginas do Admin
│   ├── dashboard.php
│   ├── employees.php       # Lista de funcionários
│   ├── employee-form.php   # Cadastro / edição
│   ├── employee-details.php
│   ├── employee-delete.php
│   ├── certificates.php
│   ├── certificate-form.php
│   ├── certificate-delete.php
│   └── settings.php
├── employee/
│   └── certificates.php    # "Meus Certificados"
├── superadmin/
│   ├── dashboard.php
│   └── companies.php
├── public/
│   ├── certificate.php     # Validação pública via QR Code
│   └── assets/
│       └── style.css
├── sql/
│   └── schema.sql          # ⚠️ Importe este arquivo no phpMyAdmin
├── uploads/                # Pasta para futuras imagens
├── index.php               # Página inicial (landing)
├── login.php               # Página de login
├── landing.html            # Conteúdo da landing (servido por index.php)
├── logout.php
└── .htaccess
```

## 🚀 Como instalar na Hostinger

### 1. Criar o banco de dados

1. Entre no **hPanel** da Hostinger
2. Vá em **Bancos de Dados → Bancos de Dados MySQL**
3. Clique em **Criar novo banco MySQL**
4. Anote:
   - **Nome do banco** (exemplo: `u123456789_carteira`)
   - **Usuário** (exemplo: `u123456789_user`)
   - **Senha**
5. Clique em **Acessar phpMyAdmin** ao lado do banco recém-criado
6. Selecione o banco → aba **Importar**
7. Importe, nesta ordem:
   - `sql/schema.sql`
   - `sql/001_add_address_phone.sql`
   - `sql/002_emergency_numbers.sql`
   - `sql/003_asaas_integration.sql`
   - `sql/004_password_resets.sql`
   - `sql/005_employee_public_hash.sql`

> ✅ Pronto, o banco tem as tabelas e os usuários de teste.

### 2. Configurar as credenciais

Abra o arquivo `config/database.php` e edite:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456789_carteira');  // ← seu banco
define('DB_USER', 'u123456789_user');      // ← seu usuário
define('DB_PASS', 'sua_senha_aqui');       // ← sua senha
```

### 3. Configurar a URL base

Copie `config/config.example.php` → `config/config.php` e `config/database.example.php` → `config/database.php`, depois edite:

**config/config.php:**

```php
define('BASE_URL', 'https://seudominio.com.br');
```

> Se for instalar dentro de uma subpasta, use por exemplo: `https://seudominio.com.br/carteira`

### 4. Subir os arquivos

1. No hPanel, vá em **Arquivos → Gerenciador de Arquivos**
2. Entre na pasta `public_html/`
3. Faça upload de **todos os arquivos** desta pasta (`site-carteira-php`)
   - Você pode compactar tudo em um zip, fazer upload e extrair lá dentro
4. Garanta que a pasta `uploads/` tenha permissão **755** (clique direito → Permissões)

### 5. Pronto, acesse o site!

Abra `https://seudominio.com.br` para ver a landing. O login fica em `https://seudominio.com.br/login.php`. Use:

| Perfil | Email | Senha |
|--------|-------|-------|
| Super Admin | `super@admin.com` | `123456` |
| Admin (Empresa) | `admin@empresa.com` | `123456` |
| Colaborador | `carlos@empresa.com` | `123456` |

> ⚠️ **Importante:** depois de testar, troque essas senhas direto no banco (use `password_hash()` do PHP para gerar o hash novo) ou crie uma página de "Trocar Senha".

## 🔒 Segurança

O projeto já vem com:

- ✅ **Prepared statements** em todas as queries (proteção contra SQL Injection)
- ✅ **CSRF token** em todos os formulários
- ✅ **`htmlspecialchars`** em todas as saídas HTML (proteção contra XSS)
- ✅ **Hash bcrypt** para senhas (via `password_hash`)
- ✅ **Headers de segurança** no `.htaccess` (X-Frame-Options, etc.)
- ✅ **`.htaccess`** bloqueando acesso direto a `config/`, `includes/` e `sql/`
- ✅ **Multi-tenant** com isolamento (admin de uma empresa nunca vê dados de outra)

## 📱 Como funciona o QR Code

1. **Certificado individual:** hash único → `public/certificate.php?hash=XXXX` (validação de uma NR)
2. **Carteira do colaborador:** hash público do funcionário → `public/employee.php?hash=XXXX` (todos os certificados)
3. Imagens do QR via API [qrserver.com](https://goqr.me/api/) (requer internet no servidor)
4. Links antigos com `?id=` redirecionam automaticamente para o hash seguro

## 🎨 Customização

- **Cores:** edite as variáveis Tailwind no `includes/header.php` (`industrial-blue`, `industrial-yellow`)
- **Nome do sistema:** edite `SITE_NAME` em `config/config.php`
- **Estilo extra:** edite `public/assets/style.css`

## 📦 Planos e recursos

| Recurso | Starter | Pro+ |
|---------|---------|------|
| Limite de funcionários | 10 | 100–800+ |
| QR Code / validação | Sim | Sim |
| Alertas por e-mail (cron + manual) | Não | Sim |
| Importação CSV | Não | Sim |
| Upload PDF do certificado | Não | Sim |
| Exportação de relatórios | Não | Sim |

Catálogo centralizado em `includes/plans.php`.

## 📝 Roadmap (sugestões futuras)

- API REST para integrações externas
- Relatórios em PDF
- Filiais / multi-unidade por empresa
- QR Code gerado localmente (sem API externa)
- Testes automatizados (PHPUnit)

## ⚙️ Requisitos do servidor

- PHP **7.4 ou superior** (Hostinger usa 8.x por padrão — beleza)
- MySQL **5.7+** ou MariaDB **10.3+** (também padrão na Hostinger)
- Extensões PHP: `pdo`, `pdo_mysql`, `mbstring`, `json` (todas vêm habilitadas por padrão)
- Apache com `mod_rewrite` (padrão na Hostinger)

## 🆘 Problemas comuns

**"Erro ao conectar ao banco de dados"**
→ Confira as credenciais em `config/database.php`. Lembra que na Hostinger o `DB_HOST` é sempre `localhost`.

**Links quebrados / botões redirecionam pra lugar errado**
→ Confira a `BASE_URL` em `config/config.php`. Não pode ter barra `/` no final.

**Página em branco**
→ Habilite a exibição de erros temporariamente adicionando no topo de `config/config.php`:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

**QR Code não aparece**
→ A API do qrserver.com precisa de internet pra funcionar. Se o servidor da Hostinger tiver bloqueio de saída (raro), instale a biblioteca [endroid/qr-code](https://github.com/endroid/qr-code) via Composer.

---

Feito a partir do projeto React/Node original. Qualquer dúvida ou ajuste, é só chamar!
