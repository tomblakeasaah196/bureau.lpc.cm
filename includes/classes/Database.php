<?php
// includes/classes/Database.php

require_once __DIR__ . '/../config/db.php';

class Database {
    private static $instance = null;
    private $pdo;

    // Private constructor to enforce Singleton pattern
    private function __construct() {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch results as associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                  // True hardware prepared statements
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // We log the error securely on the server, but NEVER show it to the user
            error_log("LPC Database Connection failed: " . $e->getMessage());
            die("Erreur de connexion à la base de données. Veuillez contacter l'administrateur.");
        }
    }

    // Get the single instance of the Database
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    // Get the PDO object for queries
    public function getConnection() {
        return $this->pdo;
    }
}