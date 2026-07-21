<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$hote   = '127.0.0.1';
$base   = 'repertoire_saveurs';
$utilisateur = 'root';
$motdepasse  = '';

try {
    $pdo = new PDO(
        "mysql:host=$hote;dbname=$base;charset=utf8mb4",
        $utilisateur,
        $motdepasse,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['erreur' => 'Connexion DB impossible : ' . $e->getMessage()]);
    exit;
}

function recupererOffres(PDO $pdo, int $platId): array {
    $sql = "SELECT o.prix, off.nom AS offrant_nom, off.quartier, off.lien
            FROM offres o
            JOIN offrants off ON off.id = o.offrant_id
            WHERE o.plat_id = :plat_id
            ORDER BY o.prix ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['plat_id' => $platId]);
    return $stmt->fetchAll();
}

function formaterPlat(PDO $pdo, array $ligne): array {
    return [
        'id'          => (int) $ligne['id'],
        'nom'         => $ligne['nom'],
        'description' => $ligne['description'],
        'origine'     => $ligne['origine'],
        'categorie'   => $ligne['categorie'],
        'note'        => (float) $ligne['note'],
        'image_url'   => $ligne['image_url'],
        'offres'      => recupererOffres($pdo, (int) $ligne['id']),
    ];
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// AUTHENTIFICATION
// ============================================================
if ($action === 'connexion' && $method === 'POST') {
    $donnees = json_decode(file_get_contents('php://input'), true);
    if (!$donnees || empty($donnees['email']) || empty($donnees['mot_passe'])) {
        http_response_code(400);
        echo json_encode(['erreur' => 'Email et mot de passe requis']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE email = :email");
    $stmt->execute(['email' => $donnees['email']]);
    $utilisateur = $stmt->fetch();

    if (!$utilisateur || !password_verify($donnees['mot_passe'], $utilisateur['mot_de_passe'])) {
        http_response_code(401);
        echo json_encode(['erreur' => 'Email ou mot de passe incorrect']);
        exit;
    }

    unset($utilisateur['mot_de_passe']);
    echo json_encode(['succes' => true, 'utilisateur' => $utilisateur]);
    exit;
}

if ($action === 'inscription' && $method === 'POST') {
    $donnees = json_decode(file_get_contents('php://input'), true);
    if (!$donnees || empty($donnees['email']) || empty($donnees['mot_passe'])) {
        http_response_code(400);
        echo json_encode(['erreur' => 'Email et mot de passe requis']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = :email");
    $stmt->execute(['email' => $donnees['email']]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['erreur' => 'Cet email est déjà utilisé']);
        exit;
    }

    $hash = password_hash($donnees['mot_passe'], PASSWORD_DEFAULT);
    $nom = $donnees['nom'] ?? '';
    
    $stmt = $pdo->prepare("INSERT INTO utilisateurs (email, mot_de_passe, nom) VALUES (:email, :mot_de_passe, :nom)");
    $stmt->execute([
        'email' => $donnees['email'],
        'mot_de_passe' => $hash,
        'nom' => $nom
    ]);

    echo json_encode(['succes' => true, 'message' => 'Compte créé avec succès']);
    exit;
}

if ($action === 'reinitialisation' && $method === 'POST') {
    $donnees = json_decode(file_get_contents('php://input'), true);
    if (!$donnees || empty($donnees['email'])) {
        http_response_code(400);
        echo json_encode(['erreur' => 'Email requis']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = :email");
    $stmt->execute(['email' => $donnees['email']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['erreur' => 'Email introuvable']);
        exit;
    }

    echo json_encode(['succes' => true, 'message' => 'Lien de réinitialisation envoyé']);
    exit;
}

// ============================================================
// FAVORIS
// ============================================================
if ($action === 'favoris_plats' && $method === 'GET') {
    $utilisateur_id = (int) ($_GET['utilisateur_id'] ?? 0);
    if ($utilisateur_id <= 0) {
        http_response_code(400);
        echo json_encode(['erreur' => 'ID utilisateur requis']);
        exit;
    }
    
    $sql = "SELECT p.* FROM favoris_plats fp 
            JOIN plats p ON p.id = fp.plat_id 
            WHERE fp.utilisateur_id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $utilisateur_id]);
    $plats = $stmt->fetchAll();
    $resultats = array_map(fn($ligne) => formaterPlat($pdo, $ligne), $plats);
    echo json_encode(['favoris' => $resultats]);
    exit;
}

if ($action === 'favoris_restaurants' && $method === 'GET') {
    $utilisateur_id = (int) ($_GET['utilisateur_id'] ?? 0);
    if ($utilisateur_id <= 0) {
        http_response_code(400);
        echo json_encode(['erreur' => 'ID utilisateur requis']);
        exit;
    }
    
    $sql = "SELECT off.* FROM favoris_restaurants fr 
            JOIN offrants off ON off.id = fr.offrant_id 
            WHERE fr.utilisateur_id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $utilisateur_id]);
    echo json_encode(['favoris' => $stmt->fetchAll()]);
    exit;
}

if ($action === 'ajouter_favori_plat' && $method === 'POST') {
    $donnees = json_decode(file_get_contents('php://input'), true);
    if (!$donnees || empty($donnees['utilisateur_id']) || empty($donnees['plat_id'])) {
        http_response_code(400);
        echo json_encode(['erreur' => 'Données manquantes']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO favoris_plats (utilisateur_id, plat_id) VALUES (:user, :plat)");
        $stmt->execute(['user' => $donnees['utilisateur_id'], 'plat' => $donnees['plat_id']]);
        echo json_encode(['succes' => true, 'message' => 'Ajouté aux favoris']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo json_encode(['succes' => false, 'erreur' => 'Déjà dans les favoris']);
        } else {
            throw $e;
        }
    }
    exit;
}

if ($action === 'supprimer_favori_plat' && $method === 'DELETE') {
    $utilisateur_id = (int) ($_GET['utilisateur_id'] ?? 0);
    $plat_id = (int) ($_GET['plat_id'] ?? 0);
    if ($utilisateur_id <= 0 || $plat_id <= 0) {
        http_response_code(400);
        echo json_encode(['erreur' => 'Paramètres invalides']);
        exit;
    }
    
    $stmt = $pdo->prepare("DELETE FROM favoris_plats WHERE utilisateur_id = :user AND plat_id = :plat");
    $stmt->execute(['user' => $utilisateur_id, 'plat' => $plat_id]);
    echo json_encode(['succes' => true, 'message' => 'Retiré des favoris']);
    exit;
}

if ($action === 'ajouter_favori_restaurant' && $method === 'POST') {
    $donnees = json_decode(file_get_contents('php://input'), true);
    if (!$donnees || empty($donnees['utilisateur_id']) || empty($donnees['offrant_id'])) {
        http_response_code(400);
        echo json_encode(['erreur' => 'Données manquantes']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO favoris_restaurants (utilisateur_id, offrant_id) VALUES (:user, :offrant)");
        $stmt->execute(['user' => $donnees['utilisateur_id'], 'offrant' => $donnees['offrant_id']]);
        echo json_encode(['succes' => true, 'message' => 'Ajouté aux favoris']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo json_encode(['succes' => false, 'erreur' => 'Déjà dans les favoris']);
        } else {
            throw $e;
        }
    }
    exit;
}

if ($action === 'supprimer_favori_restaurant' && $method === 'DELETE') {
    $utilisateur_id = (int) ($_GET['utilisateur_id'] ?? 0);
    $offrant_id = (int) ($_GET['offrant_id'] ?? 0);
    if ($utilisateur_id <= 0 || $offrant_id <= 0) {
        http_response_code(400);
        echo json_encode(['erreur' => 'Paramètres invalides']);
        exit;
    }
    
    $stmt = $pdo->prepare("DELETE FROM favoris_restaurants WHERE utilisateur_id = :user AND offrant_id = :offrant");
    $stmt->execute(['user' => $utilisateur_id, 'offrant' => $offrant_id]);
    echo json_encode(['succes' => true, 'message' => 'Retiré des favoris']);
    exit;
}

// ============================================================
// PLATS
// ============================================================
switch ($action) {
    case 'recherche':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['erreur' => 'Méthode non autorisée']); exit; }
        $terme = trim($_GET['q'] ?? '');
        if ($terme === '') { echo json_encode(['resultats' => []]); exit; }
        $sql = "SELECT * FROM plats WHERE nom LIKE :terme ORDER BY note DESC LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['terme' => '%' . $terme . '%']);
        $plats = $stmt->fetchAll();
        $resultats = array_map(fn($ligne) => formaterPlat($pdo, $ligne), $plats);
        echo json_encode(['resultats' => $resultats]);
        break;

    case 'aleatoire':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['erreur' => 'Méthode non autorisée']); exit; }
        $sql = "SELECT * FROM plats ORDER BY RAND() LIMIT 8";
        $stmt = $pdo->query($sql);
        $plats = $stmt->fetchAll();
        $resultats = array_map(fn($ligne) => formaterPlat($pdo, $ligne), $plats);
        echo json_encode(['resultats' => $resultats]);
        break;

    case 'trier':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['erreur' => 'Méthode non autorisée']); exit; }
        $critere = $_GET['critere'] ?? 'nom';
        $ordre = strtoupper($_GET['ordre'] ?? 'ASC');
        $limite = (int) ($_GET['limite'] ?? 30);
        $criteres_autorises = ['nom', 'note', 'origine', 'categorie', 'id'];
        if (!in_array($critere, $criteres_autorises)) { http_response_code(400); echo json_encode(['erreur' => 'Critère invalide']); exit; }
        if (!in_array($ordre, ['ASC', 'DESC'])) $ordre = 'ASC';
        $sql = "SELECT * FROM plats ORDER BY $critere $ordre LIMIT :limite";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        $plats = $stmt->fetchAll();
        $resultats = array_map(fn($ligne) => formaterPlat($pdo, $ligne), $plats);
        echo json_encode(['resultats' => $resultats, 'tri' => ['critere' => $critere, 'ordre' => $ordre]]);
        break;

    case 'filtrer':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['erreur' => 'Méthode non autorisée']); exit; }
        $type = $_GET['type'] ?? '';
        $valeur = $_GET['valeur'] ?? '';
        if (empty($type) || empty($valeur)) { http_response_code(400); echo json_encode(['erreur' => 'Filtre requis']); exit; }
        if (!in_array($type, ['categorie', 'origine'])) { http_response_code(400); echo json_encode(['erreur' => 'Type invalide']); exit; }
        $sql = "SELECT * FROM plats WHERE $type = :valeur ORDER BY nom ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['valeur' => $valeur]);
        $plats = $stmt->fetchAll();
        $resultats = array_map(fn($ligne) => formaterPlat($pdo, $ligne), $plats);
        echo json_encode(['resultats' => $resultats, 'filtre' => ['type' => $type, 'valeur' => $valeur]]);
        break;

    case 'categories':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['erreur' => 'Méthode non autorisée']); exit; }
        $sql = "SELECT DISTINCT categorie FROM plats ORDER BY categorie";
        $stmt = $pdo->query($sql);
        echo json_encode(['categories' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
        break;

    case 'origines':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['erreur' => 'Méthode non autorisée']); exit; }
        $sql = "SELECT DISTINCT origine FROM plats ORDER BY origine";
        $stmt = $pdo->query($sql);
        echo json_encode(['origines' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
        break;

    case 'detail':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['erreur' => 'Méthode non autorisée']); exit; }
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM plats WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $ligne = $stmt->fetch();
        if (!$ligne) { http_response_code(404); echo json_encode(['erreur' => 'Plat introuvable']); exit; }
        echo json_encode(['resultat' => formaterPlat($pdo, $ligne)]);
        break;

    case 'ajouter':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['erreur' => 'Méthode non autorisée']); exit; }
        $donnees = json_decode(file_get_contents('php://input'), true);
        if (!$donnees) { http_response_code(400); echo json_encode(['erreur' => 'Données invalides']); exit; }
        $champs_requis = ['nom', 'description', 'origine', 'categorie', 'note', 'image_url'];
        foreach ($champs_requis as $champ) {
            if (empty($donnees[$champ])) { http_response_code(400); echo json_encode(['erreur' => "Le champ '$champ' est requis"]); exit; }
        }
        $sql = "INSERT INTO plats (nom, description, origine, categorie, note, image_url) 
                VALUES (:nom, :description, :origine, :categorie, :note, :image_url)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'nom' => $donnees['nom'],
            'description' => $donnees['description'],
            'origine' => $donnees['origine'],
            'categorie' => $donnees['categorie'],
            'note' => (float) $donnees['note'],
            'image_url' => $donnees['image_url']
        ]);
        $nouvelId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("SELECT * FROM plats WHERE id = :id");
        $stmt->execute(['id' => $nouvelId]);
        $plat = $stmt->fetch();
        echo json_encode(['succes' => true, 'message' => 'Plat ajouté', 'plat' => formaterPlat($pdo, $plat)]);
        break;

    case 'supprimer':
        if ($method !== 'DELETE') { http_response_code(405); echo json_encode(['erreur' => 'Méthode non autorisée']); exit; }
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['erreur' => 'ID invalide']); exit; }
        $stmt = $pdo->prepare("SELECT id FROM plats WHERE id = :id");
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['erreur' => 'Plat non trouvé']); exit; }
        $stmt = $pdo->prepare("DELETE FROM plats WHERE id = :id");
        $stmt->execute(['id' => $id]);
        echo json_encode(['succes' => true, 'message' => 'Plat supprimé']);
        break;

    // ============================================================
    // RESTAURANTS
    // ============================================================
    case 'restaurants':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['erreur' => 'Méthode non autorisée']); exit; }
        $sql = "SELECT 
                    id, nom, quartier, lien,
                    CASE 
                        WHEN quartier LIKE '%Cocody%' THEN 5.3453
                        WHEN quartier LIKE '%Yopougon%' THEN 5.3187
                        WHEN quartier LIKE '%Plateau%' THEN 5.3364
                        WHEN quartier LIKE '%Marcory%' THEN 5.3123
                        WHEN quartier LIKE '%Abobo%' THEN 5.3687
                        WHEN quartier LIKE '%Riviera%' THEN 5.3524
                        WHEN quartier LIKE '%Bingerville%' THEN 5.3567
                        WHEN quartier LIKE '%Koumassi%' THEN 5.2953
                        WHEN quartier LIKE '%Treichville%' THEN 5.3014
                        WHEN quartier LIKE '%Angré%' THEN 5.3625
                        WHEN quartier LIKE '%Deux Plateaux%' THEN 5.3441
                        WHEN quartier LIKE '%Adjamé%' THEN 5.3553
                        ELSE 5.3364
                    END as latitude,
                    CASE 
                        WHEN quartier LIKE '%Cocody%' THEN -3.9902
                        WHEN quartier LIKE '%Yopougon%' THEN -4.0593
                        WHEN quartier LIKE '%Plateau%' THEN -4.0215
                        WHEN quartier LIKE '%Marcory%' THEN -4.0198
                        WHEN quartier LIKE '%Abobo%' THEN -4.0487
                        WHEN quartier LIKE '%Riviera%' THEN -3.9743
                        WHEN quartier LIKE '%Bingerville%' THEN -3.8833
                        WHEN quartier LIKE '%Koumassi%' THEN -3.9989
                        WHEN quartier LIKE '%Treichville%' THEN -4.0081
                        WHEN quartier LIKE '%Angré%' THEN -3.9822
                        WHEN quartier LIKE '%Deux Plateaux%' THEN -3.9942
                        WHEN quartier LIKE '%Adjamé%' THEN -4.0343
                        ELSE -4.0215
                    END as longitude
                FROM offrants";
        $stmt = $pdo->query($sql);
        $restaurants = $stmt->fetchAll();
        foreach ($restaurants as &$r) {
            $s = $pdo->prepare("SELECT COUNT(*) as total FROM offres WHERE offrant_id = :id");
            $s->execute(['id' => $r['id']]);
            $r['nb_plats'] = (int) $s->fetch()['total'];
        }
        echo json_encode(['restaurants' => $restaurants]);
        break;

    case 'restaurant_plats':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['erreur' => 'Méthode non autorisée']); exit; }
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['erreur' => 'ID invalide']); exit; }
        $sql = "SELECT p.id, p.nom, p.categorie, o.prix 
                FROM offres o 
                JOIN plats p ON p.id = o.plat_id 
                WHERE o.offrant_id = :id 
                ORDER BY o.prix ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        echo json_encode(['plats' => $stmt->fetchAll()]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['erreur' => 'Action inconnue']);
}