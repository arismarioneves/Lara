# Lara

**Assistente Virtual Inteligente com IA Avançada**

Lara é uma assistente virtual avançada que combina reconhecimento de fala, processamento de linguagem natural e síntese de voz para criar interações naturais e contextualizadas. Desenvolvida em PHP, ela oferece uma experiência conversacional fluida tanto por voz quanto por texto.

<img width="1185" height="673" alt="image" src="https://github.com/user-attachments/assets/60ec2faa-ef52-4057-88df-79ed88c8c6ee" />

## Características Principais

- **Reconhecimento de Voz**: Captura e processa comandos de voz em tempo real usando a API Web Speech
- **IA Conversacional**: Integração com OpenRouter para respostas contextualizadas usando modelos de linguagem avançados
- **Síntese de Voz (TTS)**: Suporte para múltiplos provedores de Text-to-Speech:
  - OpenAI TTS (gpt-4o-mini-tts)
  - ElevenLabs (vozes naturais e personalizadas)
- **Gerenciamento de Contexto**: Mantém o histórico da conversa para interações mais naturais
- **Modo Inteligente de Escuta**: A assistente analisa o contexto e responde apenas quando necessário
- **Interface Moderna**: Design limpo e responsivo com dark mode

## Funcionalidades

- Ativação por comando de voz ("Lara")
- Modo contínuo de conversação
- Histórico de mensagens persistente
- Entrada de texto alternativa
- Limpeza de contexto com um clique
- Indicador visual de status (aguardando, escutando, processando)
- Reprodução automática de respostas em áudio

## Requisitos

- PHP 7.4 ou superior
- Servidor web (Apache/Nginx)
- Navegador com suporte a Web Speech API (Chrome/Edge recomendados)
- Chaves de API:
  - OpenRouter API (para processamento de linguagem natural)
  - OpenAI API ou ElevenLabs API (para síntese de voz)

## Instalação

1. Clone o repositório:
```bash
git clone https://github.com/arismarioneves/Lara.git
cd lara
```

2. Copie o arquivo de configuração de exemplo:
```bash
cp config.example.php config.php
```

3. Configure suas chaves de API no arquivo [config.php](config.php):

```php
// OpenRouter
define('OPENROUTER_API_KEY', 'sua-chave-aqui');
define('OPENROUTER_MODEL', 'google/gemini-2.0-flash-001');

// TTS Provider
define('TTS_PROVIDER', 'openai'); // 'elevenlabs' ou 'openai'

// OpenAI (se usar OpenAI TTS)
define('OPENAI_API_KEY', 'sua-chave-openai');
define('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts');
define('OPENAI_TTS_VOICE', 'coral');

// ElevenLabs (se usar ElevenLabs)
define('ELEVEN_LABS_API_KEY', 'sua-chave-elevenlabs');
define('ELEVEN_LABS_VOICE_ID', 'id-da-voz');
```

4. Configure o servidor web para apontar para o diretório do projeto

5. Certifique-se de que o diretório `public/audio/` tenha permissões de escrita:
```bash
chmod 755 public/audio
```

6. Acesse a aplicação através do navegador

## Estrutura do Projeto

```
lara/
├── index.php                    # Página principal e controlador
├── audio.php                    # Servidor de arquivos de áudio
├── config.php                   # Configurações (não versionado)
├── config.example.php           # Exemplo de configurações
├── src/
│   ├── SpeechRecognition.php    # Classe de reconhecimento de fala
│   ├── OpenRouterAPI.php        # Cliente API OpenRouter
│   ├── OpenAITTS.php            # Cliente API OpenAI TTS
│   ├── ElevenLabsAPI.php        # Cliente API ElevenLabs
│   └── ConversationManager.php  # Gerenciador de contexto
├── public/
│   ├── css/
│   │   └── style.css            # Estilos da interface
│   ├── js/
│   │   └── main.js              # Lógica do frontend
│   └── audio/                   # Diretório para arquivos de áudio gerados
└── LICENSE
```

## Uso

### Interface Web

1. **Iniciar**: Clique no botão "Iniciar" para ativar o reconhecimento de voz
2. **Falar**: Diga "Lara" seguido do seu comando ou pergunta
3. **Parar**: Clique em "Parar" para desativar o reconhecimento de voz
4. **Limpar**: Clique em "Limpar" para reiniciar o contexto da conversa
5. **Texto**: Use o campo de entrada para enviar mensagens escritas

### Comportamento Inteligente

A Lara foi projetada para ser uma assistente contextual. Ela:
- Escuta continuamente quando ativada
- Responde apenas quando chamada pelo nome ou quando identifica algo relevante
- Mantém o contexto das conversas anteriores
- Analisa o ambiente e responde estrategicamente
- Retorna "off" quando não há necessidade de resposta ativa

## Configuração Avançada

### Personalização do Assistente

Edite o `ASSISTANT_PROMPT` em [config.php](config.php) para ajustar a personalidade e comportamento da assistente.

### Escolha do Modelo de IA

O sistema suporta diversos modelos através do OpenRouter:
- `google/gemini-2.0-flash-001` (padrão - rápido e eficiente)
- `anthropic/claude-3-opus`
- `openai/gpt-4-turbo`
- E muitos outros disponíveis no OpenRouter

### Vozes TTS

#### OpenAI TTS
Vozes disponíveis: `alloy`, `echo`, `fable`, `onyx`, `nova`, `shimmer`, `coral`

#### ElevenLabs
Configure o `ELEVEN_LABS_VOICE_ID` com o ID da voz desejada da sua biblioteca ElevenLabs.

## Segurança

- Nunca versione o arquivo [config.php](config.php) com suas chaves de API
- Mantenha o arquivo `.htaccess` configurado para proteger arquivos sensíveis
- Use HTTPS em produção
- Configure permissões adequadas nos diretórios do servidor

## Troubleshooting

### Reconhecimento de voz não funciona
- Verifique se está usando Chrome ou Edge
- Certifique-se de que concedeu permissão ao microfone
- O site deve estar em HTTPS (exceto localhost)

### Áudio não reproduz
- Verifique as permissões do diretório `public/audio/`
- Confirme que sua chave de API do TTS está configurada corretamente
- Verifique o console do navegador para erros

### Respostas lentas
- Considere usar um modelo mais rápido (como gemini-2.0-flash)
- Verifique sua conexão de internet
- Reduza o tamanho do contexto de conversa

## Contribuindo

Contribuições são bem-vindas! Sinta-se à vontade para:
- Reportar bugs
- Sugerir novas funcionalidades
- Enviar pull requests
- Melhorar a documentação

## Licença

Este projeto está licenciado sob a Licença MIT - veja o arquivo [LICENSE](LICENSE) para detalhes.

## Autor

Desenvolvido por **Arismário Neves**

---

**Nota**: Este projeto é para fins educacionais e demonstrativos. Use com responsabilidade e respeite os limites de uso das APIs.
