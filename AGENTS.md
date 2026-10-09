# AGENTS.md

## Sobre o projeto

Lara: assistente pessoal por voz. PHP 8.1+ e MySQL 8 no servidor (Apache 2.4), PWA no navegador. Sem framework, sem Composer e sem build de front-end: PHP puro com autoload próprio (`src/bootstrap.php`, namespace `Lara\`) e ES modules servidos direto de `assets/js/`.

Fluxo: VAD no navegador → transcrição (Whisper local ou serviço online) → pré-filtro → decisor RLCD (Jev ou adaptador LLM) → IA principal → voz. Detalhes no `README.md`.

| Caminho | Conteúdo |
| --- | --- |
| `index.php`, `setup.php` | PWA e assistente de configuração |
| `api/` | endpoints JSON |
| `src/Assistant/` | pipeline, pré-filtro, decisão, contexto |
| `src/Ai/` | clientes dos provedores (chat, transcrição, voz, decisores) |
| `src/Setup/` | catálogo de provedores, teste de chaves, geração do `config.php` |
| `assets/js/` | front-end |
| `database/schema.sql` | esquema MySQL |
| `bin/lara.php` | CLI (`migrate`, `password`, `redecide`, `stats`) |
| `tests/` | testes das regras puras: `php tests/run.php` |

## Regras do projeto

- Textos de interface, mensagens de erro e comentários em português do Brasil.
- Todo arquivo PHP começa com `declare(strict_types=1);`.
- URLs sem `.php`: o `.htaccess` serve `x` a partir de `x.php`. Links e `fetch` no front-end usam o caminho sem extensão (`api/settings`, `setup`).
- `config.php` (chaves, hash da senha, banco) fica fora do git e nunca é enviado ao navegador.
- `messages.transcript_source` e `transcript_engine` são controle interno: nunca vão para nenhuma IA nem para a interface.
- Pastas privadas (`src/`, `database/`, `bin/`, `storage/`, `tests/`) são bloqueadas pelo `.htaccess`; mantenha esse bloqueio ao mexer nas regras.

## Comentários no código

- **Descreva o estado atual, não o histórico.** O comentário diz o que o código faz e por quê agora — nunca o que era antes, o que mudou ou por que deixou de ser assim. Histórico é responsabilidade do git.

### Arquivos Temporários

- **Não criar diretórios em runtime.** Não use `mkdir()` em código de produção. Pastas necessárias devem existir previamente no projeto/deploy.
