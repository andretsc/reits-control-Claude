# REITs Control - Plugin WordPress

Plugin para controle de carteiras de FIIs e ações da B3.

## Características

- ✓ Gestão de carteiras pessoais
- ✓ Auto-criação de post privado para novos usuários
- ✓ Lista de usuários para admin
- ✓ Suporte a formatos BVMF: e .SA
- ✓ Integração com Yahoo Finance, AlphaVantage e Investing.com
- ✓ Multilíngue (PT/JP)
- ✓ Cálculo de P&L
- ✓ Sistema de logs

## Instalação

1. Faça upload da pasta `reits-control` para `/wp-content/plugins/`
2. Cole o código completo nos arquivos correspondentes
3. Ative o plugin no WordPress
4. Acesse "REITs – Controle" no menu admin

## Configuração

1. Vá em "REITs – Controle" > "Configurações"
2. Configure o provedor de API
3. Adicione chave AlphaVantage se necessário
4. Configure cache e outras opções

## Uso

### Para Usuários
- Acesse o painel "REITs – Controle"
- Adicione ativos usando o formulário
- Use formato: `ITUB4.SA` ou `BVMF:ITUB4`
- Clique em "Atualizar Agora" para buscar preços

### Para Administradores
- Acesse "Usuários" para ver todos os registrados
- Clique em "Ver Carteira" para gerenciar carteiras de usuários
- Edite ou exclua ativos de qualquer usuário

## Shortcode

Use `[reits_control_lista]` para exibir a carteira no frontend.

## Requisitos

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+

## Licença

GPL-2.0+

## Autor

Bitelecom - http://bitelecom.jp/
