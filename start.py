import os

# Define a estrutura do projeto
project_structure = {
    "": ["index.php", "config.php", ".env"],
    "src": [
        "SpeechRecognition.php",
        "GeminiAPI.php",
        "ElevenLabsAPI.php",
        "ConversationManager.php"
    ],
    "public/js": ["main.js"],
    "public/css": ["style.css"],
    "public": [],
}

# Cria diretórios e arquivos
for folder, files in project_structure.items():
    # Cria o diretório se não existir
    if folder and not os.path.exists(folder):
        os.makedirs(folder)
        print(f"Diretório criado: {folder}")

    # Cria arquivos no diretório
    for file in files:
        file_path = os.path.join(folder, file) if folder else file
        with open(file_path, 'w') as f:
            pass  # Cria um arquivo vazio
        print(f"Arquivo criado: {file_path}")

print("Estrutura do projeto 'lara' criada com sucesso!")
