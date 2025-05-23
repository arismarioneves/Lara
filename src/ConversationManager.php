<?php

class ConversationManager
{
    private $maxContextLength = 10; // Número máximo de trocas para manter no contexto

    /**
     * Obtém o contexto atual da conversa da sessão
     *
     * @return array Contexto da conversa
     */
    public function getContext()
    {
        if (!isset($_SESSION['conversation_context'])) {
            $_SESSION['conversation_context'] = [];
        }

        return $_SESSION['conversation_context'];
    }

    /**
     * Atualiza o contexto da conversa com uma nova troca
     *
     * @param string $userInput Entrada do usuário
     * @param string $assistantResponse Resposta do assistente
     */
    public function updateContext($userInput, $assistantResponse)
    {
        if (!isset($_SESSION['conversation_context'])) {
            $_SESSION['conversation_context'] = [];
        }

        // Adicionar a nova troca ao contexto
        $_SESSION['conversation_context'][] = [
            'user' => $userInput,
            'assistant' => $assistantResponse,
            'timestamp' => time()
        ];

        // Limitar o tamanho do contexto
        if (count($_SESSION['conversation_context']) > $this->maxContextLength) {
            // Remover a troca mais antiga
            array_shift($_SESSION['conversation_context']);
        }
    }

    /**
     * Limpa o contexto da conversa
     *
     * @return bool Sucesso da operação
     */
    public function clearContext()
    {
        $_SESSION['conversation_context'] = [];
        return true;
    }
}
