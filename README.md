# 0llama-Server (Zero LLAMA)
No hassle PHP Frontend for Hosting local LLMs via ollam servers (run this via cli via VSCode or any basic php web server)


0llama Web Dashboard - The All-in-One Automated OLLAMA Interface

A powerful, auto-install, auto-setup, single-file, zero-dependency web interface for managing and interacting with an Ollama instance. This dashboard is designed for developers, researchers, and AI enthusiasts who need a robust and feature-rich tool to streamline their local LLM workflows. This automatically loads and create model files for the models located in the model folder. (PLUG AND PLAY MAXIMIZED)

# 📖 Usage Guide:

## 💬 Chat Tab: 
 - The main interface for interacting with your models:
 - Select a model from the dropdown.
 - Type your message and send.
 - Click any message bubble (user or assistant) to open the Message Inspector on the right. Here you can edit, regenerate, copy, delete, or view the raw API data  - for that message.

## 📦 Models Tab: 
 - View all models currently available to Ollama. 
 - You can delete models from here.

## 🗂️ File Manager Tab: 
 - View and manage .gguf files on the server's disk. 
 - Upload new models or create new Ollama models from existing models (loaded in ./models).

## ☁️ Pull from Hub Tab: 
 - Type a model name (e.g., llama3:latest) and hit "Pull Model" to start the download as a background task.

## ⚙️ Settings Tab: 
 - Select a model from the dropdown at the top of the chat tab to manage its specific parameters. 
 - If "Global Defaults" is selected, you are editing the fallback configuration. Save your changes or create presets for easy recall.

## ⚙️ Preset Management: 
 - Save and load entire parameter configurations as named presets to quickly switch between different personalities or task requirements.

## 🔌 API Tab: Select a model in the chat tab, and this tab will update with ready-to-use code snippets for integrating your model into other applications.


# ✨ Key Features:
 - This dashboard combines the best of real-time interaction and robust background processing into a single, cohesive file.

## 💬 Modern, Feature-Rich Chat Interface:
  - Two-Column Inspector UI: A unique layout keeps the main chat log clean while providing deep functionality. Click any message to open the Message Inspector.

## Message Actions:**
 - 📝 Edit: Modify your prompts directly in the inspector and save the changes.
 - 🔄 Regenerate: Instantly get a new response from the AI for any turn in the conversation.
 - 📋 Copy: Easily copy the raw text of any message.
 - 🗑️ Delete: Remove messages to clean up or refine the conversation history.

Developer Insights: View the exact JSON payload sent to the Ollama API for any assistant response, perfect for debugging and prompt engineering.
Markdown & Code Rendering: Responses are beautifully rendered with full Markdown support, including syntax-highlighted code blocks with a one-click "Copy" button.
List & Delete: View all installed Ollama models and remove them with a single click.
GGUF File Manager: Manage your local .gguf model files directly from the UI.

# ⚙️ Comprehensive Model Management:
  - ⬆️ Upload: Upload new GGUF files to your models directory:
  - ✍️ Create Model: Create a new Ollama model from an uploaded GGUF file using default parameters. The creation process runs as a resilient background task.
  - ☁️ Pull from Hub: Pull new models directly from the Ollama Hub.

# 🚀 Resilient Background Task Engine**
Fire-and-Forget: Long-running tasks like ollama pull or ollama create are executed as background processes on the server.
Real-time Progress: A modal window provides a live log of the task's output.

Connection-Proof: You can safely close your browser tab—the task will continue running on the server. Re-opening the dashboard will automatically reconnect to the running task's progress view.

# 🪄 Task Control: 
 - Stop any running background task directly from the UI.
   
# 🔌 OpenAI-Compatible API Hub:
Dynamic Examples: The API tab provides up-to-date, copy-paste-ready code examples for interacting with your models via Ollama's OpenAI-compatible /v1/ endpoints.**

# Multi-Language Support: Includes examples for:
cURL
Python (openai library)
JavaScript (openai library)
Vercel AI SDK

# 🛠️ Advanced Configuration:
Global & Per-Model Settings: Define default parameters (temperature, system prompt, etc.) globally, and override them with specific settings for individual models.**


# 🏗️ Architecture:
The dashboard is intentionally built as a single PHP file, making it incredibly portable and easy to deploy. It requires no build steps, package managers, or external dependencies.

It operates on a hybrid backend architecture:

Real-Time Streaming Proxy: For interactive chat, the backend acts as a low-latency proxy, piping the response stream from Ollama's /api/chat endpoint directly to the client.

Stateful Polling for Background Tasks: For long-running commands, the backend initiates a background process and saves its state (PID and output log) to files. The frontend then polls a status endpoint to get live updates, ensuring robustness against network interruptions.

# 🚀 Setup & Installation:

Getting started is simple.

## Prerequisites:
A web server with PHP installed (e.g., Apache, Nginx with PHP-FPM).
Ollama installed and running on the same machine.
The posix PHP extension is recommended for robust process management.

## Instructions:
Download the File: Download the single index.php file from this repository.
Place in Web Root: Place the index.php file in a directory served by your web server (e.g., /var/www/html/ollama-dashboard/).

## Set Permissions: The PHP script needs to be able to write to its own directory to create the models/ folder, the configuration file (ollama_config.json), and the task state files (ollama_task.pid, ollama_task.log). Ensure the web server user (e.g., www-data) has write permissions on the directory.

# Navigate to your web directory
cd /var/www/html/
# Create the dashboard directory and give ownership to the web server
sudo mkdir ollama-dashboard
sudo chown www-data:www-data ollama-dashboard
# Now place the index.php file inside


Access in Browser: Open your web browser and navigate to the corresponding URL (e.g., http://localhost/ollama-dashboard/).

The application will automatically create the necessary configuration and model files on the first run.

# 🤝 Contributing

Contributions are welcome! If you have ideas for new features, bug fixes, or improvements, please feel free to open an issue or submit a pull request.

** SEO : (run models locally - ollama for (mac|windows|linux) - ollama wrapper - run llm - ai tools - llama.cpp) **
