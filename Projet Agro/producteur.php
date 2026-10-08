<?php
session_start();
require_once 'config.php';

// 1. SÉCURITÉ : Vérification de la session producteur
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'producteur') {
    header('Location: index.php');
    exit;
}

$producteur_id = $_SESSION['user_id'];
$erreur = "";
$succes = "";

// =========================================================================
// 1B. API DE RAFRAÎCHISSEMENT DES NOTIFICATIONS (AJOUTÉ)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'fetch_notifications') {
    header('Content-Type: application/json');
    
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 5");
    $stmt->execute([$producteur_id]);
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND statut = 'non_lu'");
    $stmt_count->execute([$producteur_id]);
    $non_lues = $stmt_count->fetchColumn();

    echo json_encode(['notifications' => $notifs, 'non_lues' => $non_lues]);
    exit;
}

// =========================================================================
// 2. TRAITEMENT DES ACTIONS (POST & GET)
// =========================================================================

// --- ACTION A : METTRE EN VENTE / MODIFIER UN STOCK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enregistrer_stock') {
    $type_produit = $_POST['type_produit']; 
    $quantite = intval($_POST['quantite']);
    $prix = intval($_POST['prix_demande']);

    if ($quantite <= 0 || $prix <= 0) {
        $erreur = "Veuillez remplir tous les champs correctement avec des valeurs positives supérieures à zéro.";
    } else {
        $produit_id = 0;

        if ($type_produit === 'nouveau') {
            $nom_nouveau = trim($_POST['nom_nouveau']);
            $unite_nouvelle = trim($_POST['unite_nouvelle']);

            if (!empty($nom_nouveau)) {
                $stmt_prod = $pdo->prepare("INSERT INTO produits (nom, prix_marche_unitaire, unite) VALUES (?, ?, ?)");
                $stmt_prod->execute([$nom_nouveau, $prix, $unite_nouvelle]);
                $produit_id = $pdo->lastInsertId();
            } else {
                $erreur = "Le nom du nouveau produit ne peut pas être vide.";
            }
        } else {
            $produit_id = intval($_POST['produit_id']);
        }

        if ($produit_id > 0) {
            $check = $pdo->prepare("SELECT id FROM stocks WHERE producteur_id = ? AND produit_id = ?");
            $check->execute([$producteur_id, $produit_id]);
            $existe = $check->fetch();

            if ($existe) {
                $stmt = $pdo->prepare("UPDATE stocks SET quantite_dispo = ?, prix_demande = ? WHERE id = ?");
                $stmt->execute([$quantite, $prix, $existe['id']]);
                $succes = "Stock mis à jour avec succès !";
            } else {
                $stmt = $pdo->prepare("INSERT INTO stocks (producteur_id, produit_id, quantite_dispo, prix_demande) VALUES (?, ?, ?, ?)");
                $stmt->execute([$producteur_id, $produit_id, $quantite, $prix]);
                $succes = "Produit enregistré dans votre catalogue !";
            }
        }
    }
}

// --- ACTION B : PLANIFIER LE TRAJET DU CONVOI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'planifier_trajet') {
    $camion_id = intval($_POST['camion_id']);
    $provenance = trim($_POST['provenance']);
    $destination = trim($_POST['destination']);

    if (!empty($provenance) && !empty($destination)) {
        $stmt_trajet = $pdo->prepare("UPDATE camions SET provenance = ?, destination = ?, statut = 'pret_a_partir' WHERE id = ?");
        $stmt_trajet->execute([$provenance, $destination, $camion_id]);
        $succes = "Trajet du convoi validé ! Le camion est maintenant marqué 'Prêt à partir'.";
    } else {
        $erreur = "Veuillez spécifier le lieu de chargement exact et le marché de destination.";
    }
}

// =========================================================================
// [CORRIGÉ] VALIDATION PAR LE PRODUCTEUR + ALERTE DU CHAUFFEUR
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'valider_commande_producteur') {
    $commande_id = intval($_POST['commande_id']);

    $pdo->beginTransaction();
    try {
        // 1. Validation de la commande par le producteur
        $stmt = $pdo->prepare("UPDATE commandes SET statut = 'valide_par_producteur' WHERE id = ?");
        $stmt->execute([$commande_id]);

        // 2. Trouver l'ID du chauffeur associé au camion de cette commande
        $stmt_chauffeur = $pdo->prepare("
            SELECT cam.chauffeur_id 
            FROM commandes c
            JOIN camions cam ON c.camion_id = cam.id
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt_chauffeur->execute([$commande_id]);
        $chauffeur_user_id = $stmt_chauffeur->fetchColumn();

        // 3. Si un chauffeur est assigné au camion, on lui envoie l'alerte
        if ($chauffeur_user_id) {
            $nom_coop = $_SESSION['nom'] ?? "Une coopérative";
            $msg_chauffeur = "📦 Nouvelle cargaison : La coopérative $nom_coop a validé les colis pour la commande N°$commande_id. Prêt pour la collecte !";

            $insert_notif_chauffeur = $pdo->prepare("
                INSERT INTO notifications (user_id, message, statut) 
                VALUES (?, ?, 'non_lu')
            ");
            $insert_notif_chauffeur->execute([$chauffeur_user_id, $msg_chauffeur]);
        }

        $pdo->commit();
        $succes = "Commande validée et chauffeur alerté !";
    } catch (Exception $e) {
        $pdo->rollBack();
        $erreur = "Erreur : " . $e->getMessage();
    }
}

// --- ACTION C : SUPPRIMER UN PRODUIT DU STOCK ---
if (isset($_GET['delete_stock_id'])) {
    $delete_id = intval($_GET['delete_stock_id']);
    $stmt = $pdo->prepare("DELETE FROM stocks WHERE id = ? AND producteur_id = ?");
    $stmt->execute([$delete_id, $producteur_id]);
    $succes = "Produit retiré de votre stock.";
}

// --- ACTION D : CONFIRMATION D'UNE COMMANDE CLIENT ---
if (isset($_GET['confirmer_commande_id'])) {
    $commande_id = intval($_GET['confirmer_commande_id']);
    
    $verif_commande = $pdo->prepare("
        SELECT c.id FROM commandes c
        JOIN stocks s ON c.produit_id = s.produit_id
        WHERE c.id = ? AND s.producteur_id = ? AND c.statut_paiement = 'en_attente'
    ");
    $verif_commande->execute([$commande_id, $producteur_id]);
    
    if ($verif_commande->fetch()) {
        $stmt_conf = $pdo->prepare("UPDATE commandes SET statut_paiement = 'paye' WHERE id = ?");
        $stmt_conf->execute([$commande_id]);
        $succes = "La commande a été validée avec succès !";
    } else {
        $erreur = "Action impossible ou commande introuvable.";
    }
}

// --- ACTION E : ANNULATION / REJET D'UNE COMMANDE ---
if (isset($_GET['annuler_commande_id'])) {
    $commande_id = intval($_GET['annuler_commande_id']);
    
    $verif_commande = $pdo->prepare("
        SELECT c.id FROM commandes c
        JOIN stocks s ON c.produit_id = s.produit_id
        WHERE c.id = ? AND s.producteur_id = ? AND c.statut_paiement = 'en_attente'
    ");
    $verif_commande->execute([$commande_id, $producteur_id]);
    
    if ($verif_commande->fetch()) {
        $stmt_annul = $pdo->prepare("UPDATE commandes SET statut_paiement = 'annule' WHERE id = ?");
        $stmt_annul->execute([$commande_id]);
        $succes = "La commande a été déclinée.";
    } else {
        $erreur = "Impossible d'annuler cette commande.";
    }
}

// --- ACTION F : ENREGISTRER UN CHAUFFEUR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_livreur') {
    $nom_livreur = trim($_POST['nom_complet']);
    $tel_livreur = trim($_POST['telephone']);
    $date_naiss = $_POST['date_naissance'];
    $pass_livreur = password_hash($_POST['mot_de_passe'], PASSWORD_BCRYPT);

    if (!empty($nom_livreur) && !empty($tel_livreur) && !empty($_POST['mot_de_passe'])) {
        try {
            $sql = "INSERT INTO livreurs (producteur_id, nom_complet, telephone, mot_de_passe, date_naissance, statut) VALUES (?, ?, ?, ?, ?, 'En attente de chargement')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$producteur_id, $nom_livreur, $tel_livreur, $pass_livreur, $date_naiss]);
            $succes = "Chauffeur enregistré avec succès !";
        } catch (PDOException $e) {
            $erreur = "Ce numéro de téléphone est déjà attribué à un livreur.";
        }
    }
}

// --- ACTION G : MODIFIER LE MOT DE PASSE DU PRODUCTEUR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier_mot_de_passe') {
    $ancien_pass = $_POST['ancien_pass'];
    $nouveau_pass = $_POST['nouveau_pass'];
    $confirmer_pass = $_POST['confirmer_pass'];

    if (empty($ancien_pass) || empty($nouveau_pass) || empty($confirmer_pass)) {
        $erreur = "Tous les champs de mot de passe sont obligatoires.";
    } elseif ($nouveau_pass !== $confirmer_pass) {
        $erreur = "Le nouveau mot de passe et sa confirmation ne correspondent pas.";
    } elseif (strlen($nouveau_pass) < 4) {
        $erreur = "Le nouveau mot de passe doit contenir au moins 4 caractères.";
    } else {
        $stmt_user = $pdo->prepare("SELECT mot_de_passe FROM producteurs WHERE id = ?");
        $stmt_user->execute([$producteur_id]);
        $user = $stmt_user->fetch();

        if ($user && password_verify($ancien_pass, $user['mot_de_passe'])) {
            $nouveau_pass_hash = password_hash($nouveau_pass, PASSWORD_BCRYPT);
            $update_pass = $pdo->prepare("UPDATE producteurs SET mot_de_passe = ? WHERE id = ?");
            $update_pass->execute([$nouveau_pass_hash, $producteur_id]);
            $succes = "Votre mot de passe a été modifié avec succès !";
        } else {
            $erreur = "L'ancien mot de passe est incorrect.";
        }
    }
}

// =========================================================================
// 3. CHARGEMENT DES DONNÉES
// =========================================================================

$produits_liste = $pdo->query("SELECT * FROM produits ORDER BY nom ASC")->fetchAll();

$stmt_stock = $pdo->prepare("SELECT s.*, p.nom AS produit_nom, p.unite FROM stocks s JOIN produits p ON s.produit_id = p.id WHERE s.producteur_id = ?");
$stmt_stock->execute([$producteur_id]);
$mon_stock = $stmt_stock->fetchAll();

$revenu_potentiel = 0; $total_articles = 0;
foreach ($mon_stock as $item) {
    $revenu_potentiel += ($item['quantite_dispo'] * $item['prix_demande']);
    $total_articles += $item['quantite_dispo'];
}

$camion = $pdo->query("SELECT c.*, COALESCE(SUM(co.quantite_commande), 0) AS total_sacs_reserves FROM camions c LEFT JOIN commandes co ON c.id = co.camion_id WHERE c.statut = 'en_collecte' GROUP BY c.id LIMIT 1")->fetch();

$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM commandes c JOIN stocks s ON c.produit_id = s.produit_id WHERE s.producteur_id = ? AND c.statut_paiement = 'en_attente'");
$stmt_count->execute([$producteur_id]);
$commandes_restantes = $stmt_count->fetchColumn();

$stmt_commandes = $pdo->prepare("SELECT c.*, p.nom AS produit_nom, p.unite, a.nom_complet AS acheteur_nom, a.quartier FROM commandes c JOIN produits p ON c.produit_id = p.id JOIN acheteurs a ON c.acheteur_id = a.id JOIN stocks s ON c.produit_id = s.produit_id WHERE s.producteur_id = ? AND c.statut_paiement = 'en_attente' ORDER BY c.date_commande DESC");
$stmt_commandes->execute([$producteur_id]);
$commandes_attente = $stmt_commandes->fetchAll();

$stmt_l = $pdo->prepare("SELECT * FROM livreurs WHERE producteur_id = ? ORDER BY created_at DESC");
$stmt_l->execute([$producteur_id]);
$mes_livreurs = $stmt_l->fetchAll();

// CHARGEMENT DES COMMANDES VALILDÉES ET LIVRÉES POUR L'HISTORIQUE
$stmt_hist = $pdo->prepare("SELECT c.*, p.nom AS produit_nom, p.unite, a.nom_complet AS acheteur_nom, a.quartier, a.ville FROM commandes c JOIN produits p ON c.produit_id = p.id JOIN acheteurs a ON c.acheteur_id = a.id JOIN stocks s ON c.produit_id = s.produit_id WHERE s.producteur_id = ? AND c.statut_paiement = 'livre' ORDER BY c.date_livraison_prevue DESC");
$stmt_hist->execute([$producteur_id]);
$historique_commandes = $stmt_hist->fetchAll();

// LIRE TOUTES LES NOTIFICATIONS POUR AFFICHAGE EN TEMPS RÉEL (Vérification validation livraisons)
$stmt_notifications_flux = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 3");
$stmt_notifications_flux->execute([$producteur_id]);
$flux_notifications = $stmt_notifications_flux->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gouro-Market | Espace Producteur Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { 
            --gouro-green: #119853; 
            --gouro-green-dark: #0a6c3a;
            --gouro-orange: #ff6a00; 
            --gouro-soft-bg: #f8fafc;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--gouro-soft-bg);
            color: #334155;
        }
        .app-container { 
            border: none; 
            border-radius: 20px; 
            background: #ffffff; 
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.04) !important;
        }
        .nav-pills {
            background: #f1f5f9;
            padding: 6px;
            border-radius: 14px;
        }
        .nav-link {
            color: #64748b !important;
            border-radius: 10px !important;
            transition: all 0.2s ease;
            font-weight: 600 !important;
        }
        .nav-link.active { 
            background-color: var(--gouro-green) !important; 
            color: white !important; 
            box-shadow: 0 4px 12px rgba(17, 152, 83, 0.2);
        }
        .stat-card {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #ffffff;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .card-custom {
            border: 1px solid #f1f5f9;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.01);
        }
        .btn-gouro-primary {
            background-color: var(--gouro-green);
            color: white;
            font-weight: 600;
            border-radius: 10px;
            border: none;
            transition: background 0.2s;
        }
        .btn-gouro-primary:hover {
            background-color: var(--gouro-green-dark);
            color: white;
        }
        .btn-gouro-orange {
            background-color: var(--gouro-orange);
            color: white;
            font-weight: 600;
            border-radius: 10px;
            border: none;
        }
        .btn-gouro-orange:hover {
            background-color: #e05d00;
            color: white;
        }
        .badge-dot {
            width: 8px;
            height: 8px;
            display: inline-block;
            border-radius: 50%;
            margin-right: 6px;
        }
        .search-box {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding-left: 40px;
            transition: all 0.2s;
        }
        .search-box:focus {
            border-color: var(--gouro-green);
            box-shadow: 0 0 0 0.2rem rgba(17, 152, 83, 0.15);
        }
    </style>
</head>
<body class="py-4">

<div class="container" style="max-width: 850px;">
    <div class="app-container p-4 p-md-5">
        
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 pb-3 border-bottom">
            <div class="mb-3 mb-sm-0">
                <div class="d-flex align-items-center gap-2">
                    <span class="p-2 bg-success bg-opacity-10 text-success rounded-3">
                        <i class="bi bi-shield-check fs-4"></i>
                    </span>
                    <div>
                        <h4 class="fw-bold text-dark m-0">AgriTech Logistics</h4>
                        <p class="text-muted small m-0">Espace Producteur : <span class="text-success fw-semibold"><?= htmlspecialchars($_SESSION['nom'] ?? 'Utilisateur') ?></span></p>
                    </div>
                </div>
            </div>
            <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3 px-3 py-2 fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-box-arrow-right"></i> Déconnexion
            </a>
        </div>

        <?php if(!empty($flux_notifications)): ?>
            <div class="mb-4">
                <?php foreach($flux_notifications as $f_notif): ?>
                    <div class="alert alert-info border-0 rounded-4 p-2.5 small d-flex align-items-center gap-2 mb-2 shadow-sm" style="font-size: 13px;">
                        <i class="bi bi-bell-fill text-primary"></i> 
                        <span class="text-dark"><?= htmlspecialchars($f_notif['message']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if(!empty($erreur)): ?> 
            <div class="alert alert-danger border-0 rounded-4 p-3 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-x-circle-fill fs-5"></i> <span><?= htmlspecialchars($erreur) ?></span>
            </div> 
        <?php endif; ?>
        
        <?php if(!empty($succes)): ?> 
            <div class="alert alert-success border-0 rounded-4 p-3 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-check-circle-fill fs-5"></i> <span><?= htmlspecialchars($succes) ?></span>
            </div> 
        <?php endif; ?>

        <ul class="nav nav-pills nav-justified mb-4 gap-1 small" id="prodTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active py-25 d-flex align-items-center justify-content-center gap-2" id="dash-tab" data-bs-toggle="tab" data-bs-target="#dash" type="button" role="tab"><i class="bi bi-speedometer2"></i> Suivi</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link position-relative py-25 d-flex align-items-center justify-content-center gap-2" id="commandes-tab" data-bs-toggle="tab" data-bs-target="#commandes_gestion" type="button" role="tab">
                    <i class="bi bi-receipt"></i> Réservations
                    <?php if($commandes_restantes > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-2 border-white" style="margin-left: -15px; margin-top: 5px;"><?= $commandes_restantes ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link py-25 d-flex align-items-center justify-content-center gap-2" id="crud-tab" data-bs-toggle="tab" data-bs-target="#crud_stock" type="button" role="tab"><i class="bi bi-grid-3x3-gap"></i> Catalogue</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link py-25 d-flex align-items-center justify-content-center gap-2" id="history-tab" data-bs-toggle="tab" data-bs-target="#historique_gestion" type="button" role="tab"><i class="bi bi-clock-history"></i> Historique</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link py-25 d-flex align-items-center justify-content-center gap-2" id="livreurs-tab" data-bs-toggle="tab" data-bs-target="#livreurs_gestion" type="button" role="tab"><i class="bi bi-person-badge"></i> Chauffeurs</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link py-25 d-flex align-items-center justify-content-center gap-2" id="profil-tab" data-bs-toggle="tab" data-bs-target="#profil_gestion" type="button" role="tab"><i class="bi bi-sliders"></i> Sécurité</button>
            </li>
        </ul>

        <div class="tab-content" id="prodTabContent">
            
            <div class="tab-pane fade show active" id="dash" role="tabpanel">
                <div class="row g-3 mb-4">
                    <div class="col-sm-6">
                        <div class="p-3 stat-card shadow-sm d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small d-block mb-1 fw-medium">Valeur estimée récoltes</span>
                                <h3 class="fw-bold text-dark m-0"><?= number_format($revenu_potentiel, 0, ',', ' ') ?> <span class="fs-6 text-muted">FCFA</span></h3>
                            </div>
                            <span class="p-3 bg-success bg-opacity-10 text-success rounded-3"><i class="bi bi-cash-stack fs-4"></i></span>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 stat-card shadow-sm d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small d-block mb-1 fw-medium">Commandes en suspens</span>
                                <h3 class="fw-bold text-<?= $commandes_restantes > 0 ? 'warning' : 'success' ?> m-0"><?= $commandes_restantes ?> <span class="fs-6 text-muted">à traiter</span></h3>
                            </div>
                            <span class="p-3 bg-<?= $commandes_restantes > 0 ? 'warning' : 'success' ?> bg-opacity-10 text-<?= $commandes_restantes > 0 ? 'warning' : 'success' ?> rounded-3"><i class="bi bi-hourglass-split fs-4"></i></span>
                        </div>
                    </div>
                </div>

                <?php if ($camion): 
                    $pourcentage = $camion['capacite_max'] > 0 ? round(($camion['total_sacs_reserves'] / $camion['capacite_max']) * 100) : 0;
                ?>
                    <div class="card card-custom p-4 bg-light rounded-4 border-0 shadow-sm">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-truck text-success fs-5"></i>
                            <h6 class="fw-bold text-dark m-0">État du Convoi Collectif</h6>
                        </div>
                        
                        <div class="bg-white p-3 rounded-3 border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2 small">
                                <span class="text-muted fw-medium">Volume de chargement réservé :</span>
                                <span class="text-dark fw-bold"><?= intval($camion['total_sacs_reserves']) ?> / <?= intval($camion['capacite_max']) ?> sacs (<?= $pourcentage ?>%)</span>
                            </div>
                            <div class="progress" style="height: 8px; border-radius: 4px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $pourcentage ?>%; border-radius: 4px;"></div>
                            </div>
                        </div>

                        <?php if ($commandes_restantes > 0): ?>
                            <div class="alert alert-warning border-0 p-3 m-0 small rounded-3 d-flex align-items-start gap-2">
                                <i class="bi bi-exclamation-triangle-fill mt-05"></i>
                                <div>
                                    <strong>Planification bloquée :</strong> Vous devez accepter ou décliner les <strong><?= $commandes_restantes ?> commande(s)</strong> reçue(s) avant de valider le départ logistique de ce convoi.
                                </div>
                            </div>
                        <?php else: ?>
                            <form method="POST" class="bg-white p-3 border rounded-3 shadow-sm">
                                <input type="hidden" name="action" value="planifier_trajet">
                                <input type="hidden" name="camion_id" value="<?= intval($camion['id']) ?>">
                                
                                <div class="row g-3 mb-3">
                                    <div class="col-sm-6">
                                        <label class="form-label text-secondary small fw-semibold">Point d'enlèvement (Provenance)</label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-light text-muted border-end-0"><i class="bi bi-geo-alt"></i></span>
                                            <input type="text" name="provenance" class="form-control border-start-0 ps-0" placeholder="Ex: Entrepot Sinématiali" value="<?= htmlspecialchars($camion['provenance'] ?? '') ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label text-secondary small fw-semibold">Marché cible (Destination)</label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-light text-muted border-end-0"><i class="bi bi-flag"></i></span>
                                            <input type="text" name="destination" class="form-control border-start-0 ps-0" placeholder="Ex: Adjamé Forum, Abidjan" value="<?= htmlspecialchars($camion['destination'] ?? '') ?>" required>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-sm btn-gouro-primary w-100 py-2 d-flex align-items-center justify-content-center gap-2">
                                    <i class="bi bi-check2-circle"></i> Valider la feuille de route & autoriser le départ
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="commandes_gestion" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-dark m-0">Demandes d'achats en attente de traitement</h6>
                    <span class="badge bg-secondary rounded-pill fw-medium"><?= count($commandes_attente) ?> requêtes</span>
                </div>
                
                <?php if (empty($commandes_attente)): ?>
                    <div class="text-center py-5 text-muted bg-light border border-dashed rounded-4 small">
                        <i class="bi bi-mailbox2 fs-3 mb-2 d-block text-secondary opacity-50"></i>
                        Aucune réservation de stock en attente actuellement.
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach($commandes_attente as $cmd): ?>
                            <div class="col-12 border p-3 rounded-4 bg-white shadow-sm transition">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span class="badge bg-success bg-opacity-10 text-success rounded-3 px-2 py-1 small fw-semibold">
                                            <i class="bi bi-person-fill"></i> Grossiste : <?= htmlspecialchars($cmd['acheteur_nom']) ?>
                                        </span>
                                    </div>
                                    <span class="text-muted small fw-medium d-flex align-items-center gap-1">
                                        <i class="bi bi-geo-alt-fill text-danger"></i> <?= htmlspecialchars($cmd['quartier']) ?>
                                    </span>
                                </div>
                                <div class="p-2.5 bg-light rounded-3 my-3 border border-light">
                                    <p class="m-0 small text-secondary">
                                        Produit demandé : <strong class="text-dark fs-6"><?= htmlspecialchars($cmd['produit_nom']) ?></strong><br>
                                        Volume total : <span class="badge bg-dark fw-bold"><?= intval($cmd['quantite_commande']) ?> <?= htmlspecialchars($cmd['unite']) ?>(s)</span>
                                    </p>
                                </div>
                                <div class="d-flex gap-2 justify-content-end border-top pt-2">
                                    <a href="producteur.php?annuler_commande_id=<?= intval($cmd['id']) ?>" class="btn btn-outline-danger btn-sm rounded-3 px-3" onclick="return confirm('Refuser cette commande ?')">Rejeter</a>
                                    <a href="producteur.php?confirmer_commande_id=<?= intval($cmd['id']) ?>" class="btn btn-gouro-primary btn-sm rounded-3 px-4 fw-semibold" onclick="return confirm('Valider la réservation ?')">Accepter la vente</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="crud_stock" role="tabpanel">
                <div class="card card-custom p-4 mb-4 bg-light border-0 shadow-sm">
                    <h6 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2"><i class="bi bi-plus-circle-fill text-success"></i> Déclarer un volume disponible à la vente</h6>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="enregistrer_stock">
                        
                        <div class="mb-3 d-flex gap-4 border-bottom pb-2">
                            <div class="form-check">
                                <input class="form-check-input visual-check" type="radio" name="type_produit" id="typeExistant" value="existant" checked onclick="toggleProductInput()">
                                <label class="form-check-label small fw-semibold text-dark" for="typeExistant">Catalogue Global Standard</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input visual-check" type="radio" name="type_produit" id="typeNouveau" value="nouveau" onclick="toggleProductInput()">
                                <label class="form-check-label small fw-semibold text-success" for="typeNouveau"><i class="bi bi-plus"></i> Nouveau produit Vivrier</label>
                            </div>
                        </div>

                        <div id="box-produit-existant" class="mb-3">
                            <label class="form-label small text-muted fw-medium">Sélectionner le produit</label>
                            <select class="form-select form-select-sm rounded-3 shadow-none border" name="produit_id" id="form-produit">
                                <?php foreach($produits_liste as $p): ?>
                                    <option value="<?= intval($p['id']) ?>"><?= htmlspecialchars($p['nom']) ?> (Mesure en <?= htmlspecialchars($p['unite']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="box-produit-nouveau" class="mb-3 d-none">
                            <label class="form-label small text-muted fw-medium">Création du nouveau produit</label>
                            <div class="row g-2">
                                <div class="col-7"><input type="text" name="nom_nouveau" class="form-control form-control-sm rounded-3 shadow-none" placeholder="Nom ex: Tomate Cerise"></div>
                                <div class="col-5">
                                    <select class="form-select form-select-sm rounded-3 shadow-none" name="unite_nouvelle">
                                        <option value="sac">sac</option>
                                        <option value="panier">panier</option>
                                        <option value="régime">régime</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small text-muted fw-medium">Quantité disponible</label>
                                <input type="number" name="quantite" class="form-control form-control-sm rounded-3 shadow-none" required id="form-quantite" min="1" placeholder="Nombre">
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-muted fw-medium">Prix d'offre (FCFA)</label>
                                <input type="number" name="prix_demande" class="form-control form-control-sm rounded-3 shadow-none" required id="form-prix" min="1" placeholder="Par unité">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-gouro-orange btn-sm w-100 py-2 d-flex align-items-center justify-content-center gap-1"><i class="bi bi-cloud-arrow-up"></i> Mettre à jour le marché</button>
                    </form>
                </div>

                <h6 class="fw-bold text-dark mb-3">Vos offres actuellement visibles en ligne</h6>
                <div class="row g-2">
                    <?php if(empty($mon_stock)): ?>
                        <div class="text-center py-4 text-muted small bg-white border rounded-3">Aucune offre publiée.</div>
                    <?php else: ?>
                        <?php foreach($mon_stock as $item): ?>
                            <div class="col-12 p-3 border rounded-4 bg-white d-flex justify-content-between align-items-center shadow-sm">
                                <div>
                                    <span class="fw-bold text-dark d-block fs-6"><?= htmlspecialchars($item['produit_nom']) ?></span>
                                    <small class="text-muted">
                                        <span class="fw-semibold text-success"><?= intval($item['quantite_dispo']) ?> <?= htmlspecialchars($item['unite']) ?>(s)</span> en réserve • Prix : <strong class="text-dark"><?= number_format($item['prix_demande'], 0, ',', ' ') ?> F CFA</strong>
                                    </small>
                                </div>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-sm btn-light border rounded-3 px-2" title="Modifier" onclick="preparerModification(<?= intval($item['produit_id']) ?>, <?= intval($item['quantite_dispo']) ?>, <?= intval($item['prix_demande']) ?>)"><i class="bi bi-pencil-square text-primary"></i></button>
                                    <a href="producteur.php?delete_stock_id=<?= intval($item['id']) ?>" class="btn btn-sm btn-light border rounded-3 px-2" title="Supprimer" onclick="return confirm('Retirer ce produit ?')"><i class="bi bi-trash3-fill text-danger"></i></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="historique_gestion" role="tabpanel">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-3 gap-2">
                    <h6 class="fw-bold text-dark m-0">Historique des commandes validées et livrées</h6>
                    <span class="badge bg-success rounded-pill fw-medium"><?= count($historique_commandes) ?> livraisons terminées</span>
                </div>

                <div class="position-relative mb-3">
                    <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    <input type="text" id="historySearch" class="form-control form-control-md search-box bg-white text-dark" placeholder="Rechercher par grossiste, produit, quartier ou n° de commande...">
                </div>
                
                <?php if (empty($historique_commandes)): ?>
                    <div class="text-center py-5 text-muted bg-light border border-dashed rounded-4 small">
                        <i class="bi bi-clock-history fs-3 mb-2 d-block text-secondary opacity-50"></i>
                        Aucune commande n'a encore été marquée comme livrée.
                    </div>
                <?php else: ?>
                    <div class="row g-3" id="historyContainer">
                        <?php foreach($historique_commandes as $hist): ?>
                            <div class="col-12 border p-3 rounded-4 bg-white shadow-sm history-item transition"
                                 data-id="<?= htmlspecialchars($hist['id']) ?>"
                                 data-grossiste="<?= strtolower(htmlspecialchars($hist['acheteur_nom'])) ?>"
                                 data-produit="<?= strtolower(htmlspecialchars($hist['produit_nom'])) ?>"
                                 data-ville="<?= strtolower(htmlspecialchars($hist['ville'])) ?>"
                                 data-quartier="<?= strtolower(htmlspecialchars($hist['quartier'])) ?>">
                                
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span class="fw-bold text-dark fs-6 d-block"><?= htmlspecialchars($hist['acheteur_nom']) ?></span>
                                        <small class="text-muted font-monospace">Commande N°#<?= htmlspecialchars($hist['id']) ?></small>
                                    </div>
                                    <span class="badge bg-success text-white rounded-pill px-2.5 py-1 small fw-semibold">
                                        <i class="bi bi-check-circle-fill"></i> Livré avec succès
                                    </span>
                                </div>
                                
                                <div class="p-2.5 bg-light rounded-3 my-2 border border-light small">
                                    <div class="mb-1 text-dark">
                                        <i class="bi bi-basket text-success me-1"></i> Produit : <strong><?= htmlspecialchars($hist['produit_nom']) ?></strong> (<?= intval($hist['quantite_commande']) ?> <?= htmlspecialchars($hist['unite']) ?>s)
                                    </div>
                                    <div class="text-secondary">
                                        <i class="bi bi-geo-alt-fill text-danger me-1"></i> Destination : <?= htmlspecialchars($hist['ville']) ?>, <?= htmlspecialchars($hist['quartier']) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="noHistoryMatch" class="text-center py-5 bg-white text-muted rounded-4 shadow-sm border border-dashed d-none small">
                        <i class="bi bi-exclamation-circle fs-3 text-secondary opacity-50 d-block mb-2"></i>
                        Aucune commande validée ne correspond à vos critères de recherche.
                    </div>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="livreurs_gestion" role="tabpanel">
                <div class="card card-custom p-4 border-0 bg-light mb-4 shadow-sm">
                    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-person-plus-fill text-success"></i> Rallier un chauffeur à votre exploitation</h6>
                    <form method="POST">
                        <input type="hidden" name="action" value="ajouter_livreur">
                        <div class="mb-3">
                            <input type="text" name="nom_complet" class="form-control form-control-sm rounded-3 shadow-none" placeholder="Nom complet du chauffeur (Ex: Koné Ibrahim)" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <input type="tel" name="telephone" class="form-control form-control-sm rounded-3 shadow-none" placeholder="N° de téléphone Orange/MTN/Moov" required>
                            </div>
                            <div class="col-sm-6">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white text-muted small border-end-0">Né le</span>
                                    <input type="date" name="date_naissance" class="form-control border-start-0 shadow-none" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <input type="password" name="mot_de_passe" class="form-control form-control-sm rounded-3 shadow-none" placeholder="Attribuer un code secret d'accès application" required>
                        </div>
                        <button type="submit" class="btn btn-gouro-primary btn-sm w-100 py-2">Créer les accès livreur</button>
                    </form>
                </div>

                <h6 class="fw-bold text-dark mb-3">Chauffeurs liés à votre compte</h6>
                <div class="row g-2">
                    <?php if(empty($mes_livreurs)): ?>
                        <div class="text-center py-4 text-muted small bg-white border rounded-3">Aucun chauffeur enregistré pour l'instant.</div>
                    <?php else: ?>
                        <?php foreach($mes_livreurs as $liv): ?>
                            <div class="col-12 p-3 border rounded-4 bg-white d-flex justify-content-between align-items-center shadow-sm">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="p-2 bg-secondary bg-opacity-10 rounded-circle text-secondary"><i class="bi bi-person fs-5"></i></div>
                                    <div>
                                        <strong class="text-dark d-block"><?= htmlspecialchars($liv['nom_complet']) ?></strong>
                                        <span class="text-muted small"><i class="bi bi-telephone"></i> <?= htmlspecialchars($liv['telephone']) ?></span>
                                    </div>
                                </div>
                                <span class="badge rounded-pill bg-light text-secondary border px-3 py-1.5 fw-medium">
                                    <span class="badge-dot bg-warning"></span><?= htmlspecialchars($liv['statut']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="profil_gestion" role="tabpanel">
                <div class="card card-custom p-4 border-0 bg-light rounded-4 shadow-sm">
                    <div class="d-flex align-items-center gap-2 mb-3 text-danger">
                        <i class="bi bi-shield-lock-fill fs-5"></i>
                        <h6 class="fw-bold text-dark m-0">Modifier vos paramètres de sécurité d'accès</h6>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="modifier_mot_de_passe">
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Mot de passe actuel</label>
                            <input type="password" name="ancien_pass" class="form-control rounded-3 shadow-none border" placeholder="Saisir votre mot de passe en cours" required>
                        </div>
                        
                        <hr class="my-3 text-muted opacity-25">
                        
                        <div class="row g-3 mb-4">
                            <div class="col-sm-6">
                                <label class="form-label text-muted small fw-semibold">Nouveau mot de passe</label>
                                <input type="password" name="nouveau_pass" class="form-control rounded-3 shadow-none border" placeholder="Min. 4 caractères" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label text-muted small fw-semibold">Confirmation</label>
                                <input type="password" name="confirmer_pass" class="form-control rounded-3 shadow-none border" placeholder="Répéter le mot de passe" required>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-sm btn-danger rounded-3 w-100 py-2 fw-semibold">Enregistrer la mise à jour sécurité</button>
                    </form>
                </div>
            </div>

        </div>

        <div class="text-center mt-5 pt-3 border-top"><small class="text-muted" style="font-size: 11px; letter-spacing: 0.5px;">AgriTech Logistics Engine v1.6 • Module Coopérative Sécurisé</small></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleProductInput() {
    const isNouveau = document.getElementById('typeNouveau').checked;
    document.getElementById('box-produit-existant').classList.toggle('d-none', isNouveau);
    document.getElementById('box-produit-nouveau').classList.toggle('d-none', !isNouveau);
}
function preparerModification(id, quantite, prix) {
    document.getElementById('typeExistant').checked = true;
    toggleProductInput();
    document.getElementById('form-produit').value = id;
    document.getElementById('form-quantite').value = quantite;
    document.getElementById('form-prix').value = prix;
    
    // Activation de l'onglet catalogue via l'API Bootstrap
    const crudTab = new bootstrap.Tab(document.getElementById('crud-tab'));
    crudTab.show();
}

// ALGORITHME DE RECHERCHE ET FILTRE EN TEMPS RÉEL (HISTORIQUE DES COMMANDES VALIDÉES)
document.getElementById('historySearch').addEventListener('input', function(e) {
    const sText = e.target.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.history-item');
    let matchesFound = 0;

    rows.forEach(function(row) {
        const idCmd = row.getAttribute('data-id');
        const grossiste = row.getAttribute('data-grossiste');
        const produit = row.getAttribute('data-produit');
        const ville = row.getAttribute('data-ville');
        const quartier = row.getAttribute('data-quartier');

        if (idCmd.includes(sText) || grossiste.includes(sText) || produit.includes(sText) || ville.includes(sText) || quartier.includes(sText)) {
            row.classList.remove('d-none');
            matchesFound++;
        } else {
            row.classList.add('d-none');
        }
    });

    const emptyAlert = document.getElementById('noHistoryMatch');
    if (emptyAlert) {
        if (matchesFound === 0 && sText !== "") {
            emptyAlert.classList.remove('d-none');
        } else {
            emptyAlert.classList.add('d-none');
        }
    }
});
</script>
</body>
</html>