   <?php
   require_once __DIR__ . '/config.php';

   define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? ''));
   define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? ''));
   ?>