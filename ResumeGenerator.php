<?php
class ResumeGenerator {
    private $apiKey;
    private $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent";
    private $siteContext = "Thème : Analogies & Technologies (7ème édition, Djerba, Tunisie). Axe 1: Bio-inspiration. Axe 2: Outil cognitif. Axe 3: Jumeaux numériques. Axe 4: Éthique.";
    private $siteBiblio = "- Serres (1997, 2009)\n- Descola (2005)\n- Guattari (1992)\n- Citton (2010)\n- Hofstadter & Sander (2013)";

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    private function cleanPublications($text, $limit = 15) {
        $lines = preg_split('/(\r\n|\r|\n|;)/', $text);
        $cleaned = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) > 15) $cleaned[] = $line;
        }
        return implode("\n- ", array_slice(array_unique($cleaned), 0, $limit));
    }

    public function generate($auteur, $publicationsRaw, $motsCles, $github = "", $orcid = "") {
        $pubs = $this->cleanPublications($publicationsRaw);
        $socialLinks = "";
        if (!empty($github)) $socialLinks .= "[{{< fa brands github >}} GitHub]($github) ";
        if (!empty($orcid)) $socialLinks .= "[{{< ai orcid >}} ORCID](https://orcid.org/$orcid)";

        $systemPrompt = "Tu es un curateur scientifique pour 'Frontières Numériques 2026'. CONTEXTE: {$this->siteContext}. BIBLIO: {$this->siteBiblio}. MISSION: 1. YAML Quarto (title, categories). 2. Résumé (3-4 lignes). 3. Résonances Bibliographiques. STRUCTURE: En-tête YAML, ## Nom, Liens, Résumé, Résonances.";

        $data = ["contents" => [["parts" => [["text" => $systemPrompt . "\n\nAUTEUR: $auteur\nPUBS: $pubs\nTAGS: $motsCles"]]]]];

        $ch = curl_init($this->apiUrl . "?key=" . $this->apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        $result = json_decode($response, true);
        curl_close($ch);

        $md = $result['candidates'][0]['content']['parts'][0]['text'] ?? "Erreur.";
        if (!empty($socialLinks) && !str_contains($md, 'github')) {
            $md = preg_replace('/(## .*?\n)/', "$1$socialLinks\n\n", $md);
        }
        return $md;
    }
}
?>