<?php
class ContextDistiller {
    private $apiKey;
    //private $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-pro-preview:generateContent";
    private $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-preview:generateContent";
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    /**
     * Étape 1 : Récupérer et nettoyer le contenu textuel du site
     */
    public function fetchRawContent($url) {
        $html = file_get_contents($url);
        if (!$html) return false;

        $dom = new DOMDocument();
        // Désactiver les erreurs de parsing HTML5
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);

        // Supprimer les éléments inutiles
        $toRemove = ['script', 'style', 'nav', 'footer', 'header'];
        foreach ($toRemove as $tag) {
            foreach ($xpath->query("//$tag") as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        // Récupérer le texte du corps principal
        $text = $dom->textContent;
        // Nettoyage des espaces multiples et retours à la ligne
        return preg_replace('/\s+/', ' ', trim($text));
    }

    /**
     * Étape 2 : Appliquer le Golden Prompt via l'IA
     */
    public function distillContext($rawText) {
        $goldenPrompt = "Tu es un expert en analyse documentaire. Ta mission est d'extraire la substantifique moelle scientifique du texte brut suivant issu d'un site de conférence.
        
        CONSIGNES :
        1. Identifie la problématique centrale.
        2. Liste les 6 axes de recherche principaux.
        3. La BIBLIOGRAPHIE : Extrais TOUTES les références d'auteurs cités avec leurs concepts associés.

        Format de réponse en json : 
        {
        'CONTEXTE': [la problématique centrale],
        'AXES': [les six axes],
        'BIBLIO': [liste à puces des auteurs et concepts]
        }
        
        TEXTE BRUT : " . substr($rawText, 0, 50000); // On limite pour éviter de saturer le contexte

        $data = [
            "contents" => [
                ["parts" => [["text" => $goldenPrompt]]]
            ]
        ];

        return $this->callApi($data);
    }

    private function callApi($data) {
        $ch = curl_init($this->apiUrl . "?key=" . $this->apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        return json_decode($response, true);
    }
}
