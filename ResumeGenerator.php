<?php
class ResumeGenerator {
    private $apiKey;
    //private $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent";
    private $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-preview:generateContent";
    private $siteContext = "";// "Thème : Analogies & Technologies (7ème édition, Djerba, Tunisie). Axe 1: Bio-inspiration. Axe 2: Outil cognitif. Axe 3: Jumeaux numériques. Axe 4: Éthique.";
    private $siteBiblio = "";// "- Serres (1997, 2009)\n- Descola (2005)\n- Guattari (1992)\n- Citton (2010)\n- Hofstadter & Sander (2013)";
    private $siteAxes = "";// "- Serres (1997, 2009)\n- Descola (2005)\n- Guattari (1992)\n- Citton (2010)\n- Hofstadter & Sander (2013)";
    private $siteItem;
    private $outputFolder;    
    private $bibFile;    

    public function __construct($apiKey,$context,$siteItem,$outputFolder) {
        $this->apiKey = $apiKey;
        $this->siteContext = isset($context['context']) ? $context['context'] : "no";
        $this->siteBiblio = isset($context['axes']) ? $context['axes'] : "no";;
        $this->siteAxes = isset($context['biblio']) ? $context['biblio'] : "no";
        $this->siteItem = $siteItem;
        $this->outputFolder = $outputFolder;
    }

    private function cleanPublications($publis, $limit = 10) {
        $cleaned = []; 
        $publis = array_map(fn($p) => [...$p, '@rank' => (int)($p['@rank'] ?? 999)], $publis);
        usort($publis, fn($a, $b) => $a['@rank'] <=> $b['@rank']);
        foreach ($publis as $p) {
            if(count($cleaned) < $limit && $p["@annotation"] && $p["@annotation"]["foaf:status"] && $p["@annotation"]["foaf:status"][0]["@value"]=="author"){
                $ref = $p["@annotation"]["dcterms:isReferencedBy"][0]["@value"];
                if (strpos($ref, 'hal') === 0) {
                    $entry = $this->fetchBibtexFromHal($ref);
                } elseif (strpos($ref, '10.') === 0 || strpos($ref, 'doi') === 0) {
                    $entry = $this->fetchBibtexFromDoi($ref);
                }
                if ($entry) {
                    if (!$this->isDuplicate($entry)) {
                        // On n'ajoute que si la clé est unique
                        file_put_contents($this->bibFile, $entry . "\n\n", FILE_APPEND);
                        echo "✅ Ajoutée au .bib : " . $ref . "\n";
                    } else {
                        echo "⏭️ Déjà présente (doublon ignoré) : " . $ref . "\n";
                    }
                    // On conserve quand même l'entrée pour l'envoyer à l'IA 
                    // afin qu'elle puisse citer la clé même si elle était déjà dans le fichier
                    $cleaned[] = $entry;
                }else                
                    $cleaned[] = $p["@value"];

            }
        }
        return implode("\n- ", $cleaned);
    }

    /**
     * Vérifie si une entrée BibTeX existe déjà dans le fichier .bib
     */
    private function isDuplicate($newEntry) {

        $this->bibFile = $this->outputFolder . DIRECTORY_SEPARATOR . 'referenceAuteurs.bib';
        if (!file_exists($this->bibFile)){
            file_put_contents($this->bibFile, " ");
            return false;
        }

        // 1. Extraire la clé de la nouvelle entrée (ex: @article{CLE, ...)
        // Regex : cherche ce qui est entre le premier '{' et la première ','
        if (preg_match('/^@[^{]+\{([^,]+),/m', $newEntry, $matches)) {
            $newKey = trim($matches[1]);
            
            // 2. Lire le fichier existant
            $currentBib = file_get_contents($this->bibFile);
            
            // 3. Chercher si la clé existe déjà dans le fichier
            // On cherche "@...{newKey," pour éviter les correspondances partielles
            return (strpos($currentBib, "{" . $newKey . ",") !== false);
        }
        
        return false;
    }

    private function fetchBibtexFromHal($halId) {
        // Nettoyage de l'ID (ex: hal-03175210 -> 03175210)
        $id = str_replace('halhal','hal',$halId);
        
        // URL de l'API HAL pour l'export BibTeX
        $url = "https://api.archives-ouvertes.fr/search/?q=".$id."&wt=bibtex";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // On demande explicitement du BibTeX via les headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/x-bibtex'
        ]);

        $bibtex = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ($status == 200) ? trim($bibtex) : false;

    }
    private function fetchBibtexFromDoi($doi) {
        // Nettoyage : on s'assure que le DOI ne contient pas le préfixe https://doi.org/
        $doi = str_replace(['https://doi.org/', 'doi:'], '', $doi);
        $url = "https://doi.org/" . trim($doi);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // On demande explicitement du BibTeX via les headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/x-bibtex'
        ]);

        $bibtex = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ($status == 200) ? trim($bibtex) : false;
    }

    private function cleanMotsclefs($motsclefs, $limit = 20) {
        $cleaned = []; 
        foreach ($motsclefs as $mc) {
            if($mc["display_title"])
                $cleaned[] = $mc["display_title"];
            if(count($cleaned)==$limit) return implode("\n- ", $cleaned);
        }
        return implode("\n- ", $cleaned);
    }


    public function generate($auteur) {

        $fileName = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', 'prompt_'.$auteur['o:title']))) . ".txt";
        if (!file_exists($this->outputFolder . DIRECTORY_SEPARATOR . $fileName)){
            $pubs = $this->cleanPublications($auteur["foaf:publications"]);
            $motsclefs = $this->cleanMotsclefs($auteur["dcterms:subject"]);
            $systemPrompt = "Tu es un curateur scientifique pour {$this->siteItem['o:title']}. CONTEXTE: {$this->siteContext}. AXES: {$this->siteAxes}. BIBLIO: {$this->siteBiblio}. 
                MISSION: 1. YAML Quarto (title, categories). 2. Résumé en citant ces références via leurs clés BibTeX (3-4 lignes). 3. Proposition d'intervention dans la conférence. 4. Résonances Bibliographiques en citant ces références via leurs clés BibTeX. 
                STRUCTURE: En-tête YAML, ## Nom, Liens, Résumé, Proposition, Résonances.";
                
            $systemPrompt = "Tu es un curateur scientifique pour {$this->siteItem['o:title']}. CONTEXTE: {$this->siteContext}. AXES: {$this->siteAxes}. BIBLIO: {$this->siteBiblio}. 
                MISSION: 1. YAML Quarto (title, categories). 2. Résumé en citant ces références via leurs clés BibTeX (3-4 lignes). 3. Proposition d'intervention dans la conférence avec un titre et un résumé de 10 lignes. 4. Résonances Bibliographiques en citant ces références via leurs clés BibTeX. 
                STRUCTURE DE SORTIE :
                    ---
                    title: AUTEUR
                    categories: TAGS
    
                    bibliography: referenceAuteurs.bib

                    ---
                    
                    #### Nom
                    
                    ::: {.callout-note appearance='minimal'}
                    **Résumé :** [3-4 lignes]
                    :::

                    ### Proposition
                    
                    **Titre : [titre de la proposition]**
                    
                    **Résumé :**
                    
                    [Résumé de la proposition]
                    
                    ### Résonances Bibliographiques
                    [Lier les travaux de l'auteur à la bibliographie pivot en utilisant les balises de citation @].";


            $data = ["contents" => [["parts" => [["text" => $systemPrompt . "\n\nAUTEUR:". $auteur["o:title"]." \nPUBS: $pubs\nTAGS: $motsclefs"]]]]];
            file_put_contents($this->outputFolder . DIRECTORY_SEPARATOR . $fileName, json_encode($data));
        }else{
            $data = json_decode(file_get_contents($this->outputFolder . DIRECTORY_SEPARATOR . $fileName));            
        }

        $fileName = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '_', 'resume_'.$auteur['o:title']))) . ".json";
        if (!file_exists($this->outputFolder . DIRECTORY_SEPARATOR . $fileName)){
            $ch = curl_init($this->apiUrl . "?key=" . $this->apiKey);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $response = curl_exec($ch);
            file_put_contents($this->outputFolder . DIRECTORY_SEPARATOR . $fileName, $response);
        }else $response = file_get_contents($this->outputFolder . DIRECTORY_SEPARATOR . $fileName);  

        $result = json_decode($response, true);
        $md = $result['candidates'][0]['content']['parts'][0]['text'] ?? "Erreur.";

        //ajustement du qmd
        $socialLinks = "";
        foreach ($auteur["dcterms:isReferencedBy"] as $ref) {
            $socialLinks .= "[{{< fa solid globe >}} ".$ref['@id']."](".$ref['@id'].") ";
        }
        if (!empty($socialLinks)) {
            $md = preg_replace('/(#### .*?\n)/', "$1$socialLinks\n\n", $md);
        }
        $md = str_replace("#### ".$auteur["o:title"],"",$md);
        return $md;
    }
}
?>