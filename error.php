<?php
// error.php - Página de erro genérica

// Obter código de erro
$errorCode = isset($_GET['code']) ? intval($_GET['code']) : 500;

// Definir mensagem com base no código
switch ($errorCode) {
    case 404:
        $title = 'Página não encontrada';
        $message = 'A página que você está procurando não existe.';
        break;
    case 403:
        $title = 'Acesso negado';
        $message = 'Você não tem permissão para acessar este recurso.';
        break;
    default:
        $title = 'Erro interno';
        $message = 'Ocorreu um erro interno no servidor.';
}

// Verificar se é uma requisição AJAX
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    // Retornar erro como JSON
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => $message,
        'code' => $errorCode
    ]);
    exit;
}

// Caso contrário, exibir página de erro HTML
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erro <?php echo $errorCode; ?> - Lara</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .error-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 40px;
            text-align: center;
            max-width: 500px;
        }

        h1 {
            color: #e74c3c;
            margin-bottom: 20px;
        }

        .error-code {
            font-size: 72px;
            font-weight: bold;
            color: #e74c3c;
            margin: 0;
        }

        p {
            margin: 20px 0;
            line-height: 1.6;
        }

        a {
            color: #3498db;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .button {
            display: inline-block;
            background-color: #3498db;
            color: white;
            padding: 10px 20px;
            border-radius: 4px;
            margin-top: 20px;
            text-decoration: none;
        }

        .button:hover {
            background-color: #2980b9;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div class="error-container">
        <p class="error-code"><?php echo $errorCode; ?></p>
        <h1><?php echo $title; ?></h1>
        <p><?php echo $message; ?></p>
        <a href="/" class="button">Voltar para a página inicial</a>
    </div>
</body>

</html>