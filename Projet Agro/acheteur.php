<?php
session_start();
require_once 'config.php';

// Sécurité : Si l'utilisateur n'est pas connecté ou n'est pas acheteur
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'acheteur') {
    header('Location: index.php');
    exit;
}

// Récupération de l'identifiant de l'acheteur connecté
$acheteur_id = $_SESSION['user_id'];
$erreur = "";
$succes = "";

// Initialisation du panier s'il n'existe pas encore
if (!isset($_SESSION['panier'])) {
    $_SESSION['panier'] = [];
}

// =========================================================================
// [CORRIGÉ] API AJAX INTERNE : CHARGEMENT DES NOTIFICATIONS VIA 'user_id'
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'fetch_notifications') {
    header('Content-Type: application/json');
    try {
        // Récupérer les notifications avec la bonne colonne 'user_id'
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 10");
        $stmt->execute([$acheteur_id]);
        $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Compter les alertes non lues avec la bonne colonne 'user_id'
        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND statut = 'non_lu'");
        $stmt_count->execute([$acheteur_id]);
        $non_lues = $stmt_count->fetchColumn();

        echo json_encode([
            'status' => 'success',
            'notifs' => $notifs,
            'non_lues' => (int)$non_lues
        ]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// ACTION API : MARQUER TOUTES LES NOTIFICATIONS COMME LUES
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'marquer_lu') {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("UPDATE notifications SET statut = 'lu' WHERE user_id = ?");
    $stmt->execute([$acheteur_id]);
    echo json_encode(['status' => 'success']);
    exit;
}

// =========================================================================
// TRAITEMENT 1 : AJOUT AU PANIER (SANS AUCUNE MODIFICATION)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_panier') {
    $stock_id = intval($_POST['stock_id']);
    $quantite_demandee = intval($_POST['quantite']);

    // Vérifier les stocks disponibles réels
    $stmt = $pdo->prepare("
        SELECT s.*, p.nom AS produit_nom, p.unite 
        FROM stocks s 
        JOIN produits p ON s.produit_id = p.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$stock_id]);
    $stock_actuel = $stmt->fetch();

    if ($stock_actuel && $quantite_demandee > 0) {
        if ($quantite_demandee <= $stock_actuel['quantite_dispo']) {
            // Ajouter ou empiler dans la session panier
            if (isset($_SESSION['panier'][$stock_id])) {
                $_SESSION['panier'][$stock_id] += $quantite_demandee;
            } else {
                $_SESSION['panier'][$stock_id] = $quantite_demandee;
            }
            $succes = "L'article \"" . htmlspecialchars($stock_actuel['produit_nom']) . "\" a bien été ajouté à votre panier de groupage !";
        } else {
            $erreur = "Quantité demandée indisponible. Il ne reste que " . $stock_actuel['quantite_dispo'] . " " . $stock_actuel['unite'] . "(s).";
        }
    } else {
        $erreur = "Veuillez entrer une quantité valide supérieure à 0.";
    }
}

// =========================================================================
// TRAITEMENT 2 : RETRAIT D'UN ÉLÉMENT DU PANIER
// =========================================================================
if (isset($_GET['retirer_panier_id'])) {
    $id_retrait = intval($_GET['retirer_panier_id']);
    if (isset($_SESSION['panier'][$id_retrait])) {
        unset($_SESSION['panier'][$id_retrait]);
        $succes = "Produit retiré du panier.";
    }
}

// =========================================================================
// TRAITEMENT 3 : ACHAT GLOBAL & ROUTAGE LOGISTIQUE SECURISE (CORRIGÉ COLONNE)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'valider_commande_panier') {
    if (empty($_SESSION['panier'])) {
        $erreur = "Votre panier est vide. Veuillez ajouter des produits vivriers avant de valider.";
    } else {
        $pdo->beginTransaction();
        try {
            // 1. Trouver ou ouvrir un camion logistique en statut 'en_collecte'
            $stmt_camion = $pdo->query("SELECT id, capacite_max FROM camions WHERE statut = 'en_collecte' LIMIT 1");
            $camion_dispo = $stmt_camion->fetch();

            if (!$camion_dispo) {
                // Utilisation de l'AUTO_INCREMENT géré nativement par la BDD pour éviter Integrity constraint violation
                $insert_camion = $pdo->prepare("INSERT INTO camions (provenance, destination, capacite_max, statut) VALUES ('Yamoussoukro', 'Abidjan', 200, 'en_collecte')");
                $insert_camion->execute();
                $camion_id = $pdo->lastInsertId();
            } else {
                $camion_id = $camion_dispo['id'];
            }

            // 2. Parcourir et enregistrer chaque ligne du panier
            foreach ($_SESSION['panier'] as $stock_id => $qte) {
                // Re-vérifier la disponibilité
                $stmt_s = $pdo->prepare("SELECT s.*, p.nom FROM stocks s JOIN produits p ON s.produit_id = p.id WHERE s.id = ? FOR UPDATE");
                $stmt_s->execute([$stock_id]);
                $st = $stmt_s->fetch();

                if (!$st || $st['quantite_dispo'] < $qte) {
                    throw new Exception("Erreur de stock pour le produit : " . ($st ? $st['nom'] : "Inconnu"));
                }

                // CORRECTION : Retrait de 'prix_total' et 'statut' car ces colonnes n'existent pas dans la table 'commandes'.
                // On garde uniquement les champs valides selon DATABASE gouro_market.sql :
                // id, acheteur_id, camion_id, produit_id, quantite_commande, statut_paiement, date_commande
                $ins_cmd = $pdo->prepare("
                    INSERT INTO commandes (acheteur_id, camion_id, produit_id, quantite_commande, statut_paiement) 
                    VALUES (?, ?, ?, ?, 'en_attente')
                ");
                $ins_cmd->execute([$acheteur_id, $camion_id, $st['produit_id'], $qte]);
                $commande_nouvelle_id = $pdo->lastInsertId();

                // Soustraire le stock disponible du producteur
                $new_qte = $st['quantite_dispo'] - $qte;
                $up_stock = $pdo->prepare("UPDATE stocks SET quantite_dispo = ? WHERE id = ?");
                $up_stock->execute([$new_qte, $stock_id]);

                // Alerter automatiquement le producteur propriétaire par notification interne
                $msg_pour_producteur = "📢 Alerte Vente : Le grossiste " . $_SESSION['nom'] . " a réservé " . $qte . " unité(s) de " . $st['nom'] . ". En attente de votre validation.";
                $ins_notif = $pdo->prepare("INSERT INTO notifications (user_id, message, statut) VALUES (?, ?, 'non_lu')");
                $ins_notif->execute([$st['producteur_id'], $msg_pour_producteur]);
            }

            $pdo->commit();
            $_SESSION['panier'] = []; // Vider complètement le panier
            $succes = "Votre commande globale a été transmise avec succès aux coopératives ! Suivez l'état de validation dans vos alertes.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "Échec de validation de la commande : " . $e->getMessage();
        }
    }
}

// =========================================================================
// CHARGEMENT DE TOUTES LES DONNÉES DU CATALOGUE ET DU PANIER
// =========================================================================
$catalogue = $pdo->query("
    SELECT s.*, p.nom AS produit_nom, p.unite, pr.nom_cooperative 
    FROM stocks s 
    JOIN produits p ON s.produit_id = p.id 
    JOIN producteurs pr ON s.producteur_id = pr.id
    WHERE s.quantite_dispo > 0
    ORDER BY s.id DESC
")->fetchAll();

$items_panier = [];
$total_general_panier = 0;
if (!empty($_SESSION['panier'])) {
    foreach ($_SESSION['panier'] as $s_id => $qte) {
        $stmt_p = $pdo->prepare("
            SELECT s.*, p.nom AS produit_nom, p.unite 
            FROM stocks s 
            JOIN produits p ON s.produit_id = p.id 
            WHERE s.id = ?
        ");
        $stmt_p->execute([$s_id]);
        $row = $stmt_p->fetch();
        if ($row) {
            $row['quantite_panier'] = $qte;
            $row['total_ligne'] = $qte * $row['prix_demande'];
            $total_general_panier += $row['total_ligne'];
            $items_panier[] = $row;
        }
    }
}

// =========================================================================
// CHARGEMENT DU NOUVEL HISTORIQUE DES COMMANDES PASSÉES PAR L'ACHETEUR
// [SÉCURISÉ AVEC LES ALIAS DE COLONNES ALTERNATIVES]
// =========================================================================
$stmt_commandes_passees = $pdo->prepare("
    SELECT c.*, p.nom AS produit_nom, p.unite, cam.statut AS statut_camion
    FROM commandes c
    JOIN produits p ON c.produit_id = p.id
    LEFT JOIN camions cam ON c.camion_id = cam.id
    WHERE c.acheteur_id = ?
    ORDER BY c.date_commande DESC
");
$stmt_commandes_passees->execute([$acheteur_id]);
$mes_commandes_historique = $stmt_commandes_passees->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gouro-Market | Espace Grossiste</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --gouro-green: #2e7d32;
            --gouro-orange: #f57c00;
            --soft-bg: #f4f6f8;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--soft-bg);
            color: #333;
        }
        .main-card {
            border: none;
            border-radius: 24px;
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.02);
        }
        .nav-pills {
            background: #eee;
            padding: 4px;
            border-radius: 12px;
        }
        .nav-link {
            color: #555 !important;
            border-radius: 10px !important;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        .nav-link.active {
            background-color: var(--gouro-green) !important;
            color: white !important;
        }
        .produit-card {
            border: 1px solid #f0f0f0;
            border-radius: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
            background: #fff;
        }
        .produit-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.04);
        }
        .notif-item {
            border-radius: 12px;
            background-color: #f8f9fa;
            border-left: 4px solid #dee2e6;
            transition: all 0.2s;
        }
        .notif-item.non-lu {
            background-color: #e8f5e9;
            border-left-color: var(--gouro-green);
        }
        .search-box {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding-left: 40px;
            transition: all 0.2s;
        }
        .search-box:focus {
            border-color: var(--gouro-green);
            box-shadow: 0 0 0 0.2rem rgba(46, 125, 50, 0.15);
        }
    </style>
</head>
<body class="py-4">

<div class="container" style="max-width: 780px;">
    <div class="card main-card p-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
            <div>
                <span class="badge bg-success bg-opacity-10 text-success px-3 py-1.5 rounded-pill small fw-bold mb-1">PROFIL GROSSISTE</span>
                <h4 class="fw-bold m-0 text-dark"><?= htmlspecialchars($_SESSION['nom']) ?></h4>
            </div>
            <button class="btn btn-light border btn-sm rounded-3 p-2.5 position-relative me-2" data-bs-toggle="modal" data-bs-target="#modalNotifs" onclick="marquerCommeLuDirect()">
                <i class="bi bi-bell fs-5"></i>
                <span id="badge-notif" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" style="margin-left: -5px; margin-top: 5px;">0</span>
            </button>
            <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3 px-3 py-2 fw-semibold">Déconnexion</a>
        </div>

        <?php if(!empty($erreur)): ?>
            <div class="alert alert-danger border-0 rounded-4 small py-3 mb-3 d-flex align-items-center gap-2"><i class="bi bi-x-circle-fill fs-5"></i><span><?= $erreur ?></span></div>
        <?php endif; ?>
        <?php if(!empty($succes)): ?>
            <div class="alert alert-success border-0 rounded-4 small py-3 mb-3 d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill fs-5"></i><span><?= $succes ?></span></div>
        <?php endif; ?>

        <ul class="nav nav-pills nav-justified mb-4 gap-1 small" id="grossisteTab" role="tablist">
            <li class="nav-item">
                <button class="nav-link active py-2.5" id="marche-tab" data-bs-toggle="tab" data-bs-target="#marche" type="button" role="tab"><i class="bi bi-shop me-1"></i> Marché Vivrier</button>
            </li>
            <li class="nav-item">
                <button class="nav-link position-relative py-2.5" id="panier-tab" data-bs-toggle="tab" data-bs-target="#panier" type="button" role="tab">
                    <i class="bi bi-cart3 me-1"></i> Mon Camion
                    <?php if(count($items_panier) > 0): ?>
                        <span class="badge rounded-pill bg-danger ms-1"><?= count($items_panier) ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link py-2.5" id="historique-tab" data-bs-toggle="tab" data-bs-target="#historique" type="button" role="tab"><i class="bi bi-clock-history me-1"></i> Mes Commandes</button>
            </li>
        </ul>

        <div class="tab-content" id="grossisteTabContent">
            
            <div class="tab-pane fade show active" id="marche" role="tabpanel">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-3 gap-2">
                    <h6 class="fw-bold text-dark m-0">Produits disponibles en coopératives</h6>
                    <span class="text-muted small fw-medium"><?= count($catalogue) ?> arrivages</span>
                </div>

                <div class="position-relative mb-4">
                    <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    <input type="text" id="produitSearch" class="form-control form-control-md search-box bg-white text-dark" placeholder="Rechercher un produit vivrier (ex: Tomate, Gombo, Igname...)">
                </div>
                
                <?php if (empty($catalogue)): ?>
                    <div class="text-center py-5 text-muted bg-light rounded-4 small">Aucun arrivage de produit vivrier de Côte d'Ivoire disponible pour le moment.</div>
                <?php else: ?>
                    <div class="row g-3" id="catalogueContainer">
                        <?php foreach($catalogue as $p): ?>
                            <div class="col-sm-6 produit-item" data-nom="<?= strtolower(htmlspecialchars($p['produit_nom'])) ?>" data-coop="<?= strtolower(htmlspecialchars($p['nom_cooperative'])) ?>">
                                <div class="p-3 produit-card h-100 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex justify-content-between align-items-start mb-1">
                                            <h6 class="fw-bold m-0 text-dark"><?= htmlspecialchars($p['produit_nom']) ?></h6>
                                            <span class="badge bg-light border text-secondary small fw-normal">Au <?= htmlspecialchars($p['unite']) ?></span>
                                        </div>
                                        <small class="text-success d-block mb-3"><i class="bi bi-building-subtle"></i> Coop : <?= htmlspecialchars($p['nom_cooperative']) ?></small>
                                        
                                        <div class="d-flex justify-content-between align-items-baseline mb-3 bg-light p-2 rounded-3">
                                            <span class="small text-muted">Prix Coop :</span>
                                            <span class="fw-bold text-dark"><?= number_format($p['prix_demande'], 0, ',', ' ') ?> <span class="small font-monospace text-muted" style="font-size: 10px;">FCFA</span></span>
                                        </div>
                                    </div>
                                    
                                    <form method="POST" class="mt-2">
                                        <input type="hidden" name="action" value="ajouter_panier">
                                        <input type="hidden" name="stock_id" value="<?= intval($p['id']) ?>">
                                        
                                        <div class="row g-1 align-items-center">
                                            <div class="col-5">
                                                <input type="number" name="quantite" class="form-control form-control-sm text-center fw-bold shadow-none border" value="1" min="1" max="<?= intval($p['quantite_dispo']) ?>">
                                            </div>
                                            <div class="col-7">
                                                <button type="submit" class="btn btn-sm btn-success w-100 fw-bold rounded-3 py-1.5 px-1 small text-nowrap"><i class="bi bi-plus-lg"></i> Charger</button>
                                            </div>
                                        </div>
                                        <div class="text-end mt-1"><small class="text-muted" style="font-size: 11px;">Reste : <?= intval($p['quantite_dispo']) ?> <?= htmlspecialchars($p['unite']) ?>(s)</small></div>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="noProductMatch" class="text-center py-5 bg-white text-muted rounded-4 shadow-sm border border-dashed d-none small">
                        <i class="bi bi-search-heart fs-3 text-secondary opacity-50 d-block mb-2"></i>
                        Aucun produit vivrier ne correspond à votre recherche actuelle.
                    </div>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="panier" role="tabpanel">
                <h6 class="fw-bold text-dark mb-3">Contenu du convoi de groupage en cours</h6>
                
                <?php if (empty($items_panier)): ?>
                    <div class="text-center py-5 text-muted bg-light rounded-4 small">
                        <i class="bi bi-cart-x fs-3 d-block mb-2 text-secondary opacity-50"></i>
                        Votre panier est vide. Visitez le Marché pour y charger des produits vivriers.
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-2 mb-4">
                        <?php foreach($items_panier as $item): ?>
                            <div class="p-3 border rounded-4 bg-white d-flex justify-content-between align-items-center shadow-sm">
                                <div>
                                    <span class="fw-bold text-dark d-block"><?= htmlspecialchars($item['produit_nom']) ?></span>
                                    <small class="text-muted">Quantité : <strong class="text-success"><?= intval($item['quantite_panier']) ?> <?= htmlspecialchars($item['unite']) ?>(s)</strong> • Unit : <?= number_format($item['prix_demande'], 0, ',', ' ') ?> F</small>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <span class="fw-bold text-dark small font-monospace"><?= number_format($item['total_ligne'], 0, ',', ' ') ?> F</span>
                                    <a href="acheteur.php?retirer_panier_id=<?= intval($item['id']) ?>" class="btn btn-sm btn-light border rounded-3 px-2 text-danger" title="Retirer"><i class="bi bi-trash3-fill"></i></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="bg-light p-3 rounded-4 mb-3 border border-light-subtle d-flex justify-content-between align-items-center">
                        <span class="fw-semibold text-secondary small">Total estimé de la commande :</span>
                        <h4 class="fw-bold text-dark m-0 font-monospace"><?= number_format($total_general_panier, 0, ',', ' ') ?> <span class="fs-6 text-muted">FCFA</span></h4>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="valider_commande_panier">
                        <button type="submit" class="btn btn-success w-100 py-2.5 fw-bold rounded-3 shadow-sm d-flex align-items-center justify-content-center gap-2">
                            <i class="bi bi-shield-check-fill fs-5"></i> Confirmer la réservation & le routage logistique
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="historique" role="tabpanel">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-3 gap-2">
                    <h6 class="fw-bold text-dark m-0">Historique complet de vos commandes passées</h6>
                    <span class="badge bg-secondary rounded-pill fw-medium"><?= count($mes_commandes_historique) ?> fiches</span>
                </div>

                <div class="position-relative mb-4">
                    <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    <input type="text" id="commandeSearch" class="form-control form-control-md search-box bg-white text-dark" placeholder="Rechercher par produit, ID, statut (ex: paye, Livre, en attente)...">
                </div>

                <?php if (empty($mes_commandes_historique)): ?>
                    <div class="text-center py-5 text-muted bg-light rounded-4 small">
                        <i class="bi bi-folder-x fs-3 d-block mb-2 text-secondary opacity-50"></i>
                        Vous n'avez pas encore passé de commande sur la plateforme.
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3" id="commandeHistoryContainer">
                        <?php foreach($mes_commandes_historique as $cmd): 
                            // Résolution dynamique des indices pour éviter les avertissements PHP
                            // Comme prix_total n'existe pas en base de données, le calcul se fait en direct
                            $prix_total_reel = $cmd['prix_total'] ?? $cmd['total_facture'] ?? ($cmd['quantite_commande'] * ($cmd['prix_unitaire'] ?? 0));
                            $statut_flux_reel = $cmd['statut'] ?? $cmd['statut_commande'] ?? 'actif';
                            
                            $badge_color = 'bg-secondary';
                            $badge_text = htmlspecialchars($cmd['statut_paiement'] ?? 'Inconnu');
                            if (($cmd['statut_paiement'] ?? '') === 'en_attente') { $badge_color = 'bg-warning text-dark'; $badge_text = 'En attente'; }
                            if (($cmd['statut_paiement'] ?? '') === 'paye') { $badge_color = 'bg-primary text-white'; $badge_text = 'Payé (Chargé)'; }
                            if (($cmd['statut_paiement'] ?? '') === 'livre') { $badge_color = 'bg-success text-white'; $badge_text = 'Livré'; }
                            if (($cmd['statut_paiement'] ?? '') === 'annule') { $badge_color = 'bg-danger text-white'; $badge_text = 'Annulé'; }
                        ?>
                            <div class="p-3 border rounded-4 bg-white shadow-sm history-item" 
                                 data-id="<?= htmlspecialchars($cmd['id']) ?>"
                                 data-produit="<?= strtolower(htmlspecialchars($cmd['produit_nom'])) ?>"
                                 data-statut="<?= strtolower($badge_text) ?>"
                                 data-statutraw="<?= strtolower(htmlspecialchars($cmd['statut_paiement'] ?? '')) ?>">
                                
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span class="fw-bold text-dark fs-6 d-block"><?= htmlspecialchars($cmd['produit_nom']) ?></span>
                                        <small class="text-muted font-monospace">ID Commande : #<?= htmlspecialchars($cmd['id']) ?> • le <?= date('d/m/Y H:i', strtotime($cmd['date_commande'])) ?></small>
                                    </div>
                                    <span class="badge <?= $badge_color ?> rounded-pill px-2.5 py-1 small fw-semibold"><?= $badge_text ?></span>
                                </div>

                                <div class="p-2.5 bg-light rounded-3 my-2 border border-light small text-dark d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="bi bi-basket3-fill text-success me-1"></i> Quantité commandée : <strong><?= intval($cmd['quantite_commande']) ?> <?= htmlspecialchars($cmd['unite']) ?>(s)</strong>
                                    </div>
                                    <div class="fw-bold font-monospace text-dark">
                                        <!-- Affichage calculé car non stocké en BDD -->
                                        <?= number_format((float)$prix_total_reel, 0, ',', ' ') ?> F CFA
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center mt-2 pt-1">
                                    <small class="text-muted"><i class="bi bi-truck me-1"></i> Logistique : <?= htmlspecialchars($cmd['statut_camion'] ?? 'En attente de convoi') ?></small>
                                    <small class="text-secondary fw-medium bg-secondary bg-opacity-10 px-2 py-0.5 rounded-2">Flux : <?= htmlspecialchars($statut_flux_reel) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="noCommandeMatch" class="text-center py-5 bg-white text-muted rounded-4 shadow-sm border border-dashed d-none small">
                        <i class="bi bi-receipt-cutoff fs-3 text-secondary opacity-50 d-block mb-2"></i>
                        Aucune commande passée ne correspond à vos critères de recherche.
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <div class="text-center mt-5 pt-3 border-top"><small class="text-muted" style="font-size: 11px; letter-spacing: 0.5px;">AgriTech Multi-Tenant Framework v2.1 • Module Grossiste Connecté</small></div>
    </div>
</div>

<div class="modal fade" id="modalNotifs" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content border-0 rounded-4 shadow">
            <div class="modal-header bg-success text-white rounded-top-4 py-3">
                <h6 class="modal-title fw-bold m-0"><i class="bi bi-bell-fill me-2"></i> Alertes Logistiques & Commandes</h6>
                <button type="button" class="btn-close btn-close-white shadow-none small" data-bs-submit="modal" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3" style="max-height: 400px; overflow-y: auto;" id="conteneur-notifs-ajax">
                <div class="text-center py-4 text-muted small">Aucune nouvelle notification reçue.</div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// MOTEUR DE RECHERCHE DYNAMIQUE : CATALOGUE PRODUITS
document.getElementById('produitSearch').addEventListener('input', function(e) {
    const query = e.target.value.toLowerCase().trim();
    const products = document.querySelectorAll('.produit-item');
    let foundCount = 0;

    products.forEach(function(item) {
        const nomProduit = item.getAttribute('data-nom');
        const nomCoop = item.getAttribute('data-coop');

        if (nomProduit.includes(query) || nomCoop.includes(query)) {
            item.classList.remove('d-none');
            foundCount++;
        } else {
            item.classList.add('d-none');
        }
    });

    const noProdMsg = document.getElementById('noProductMatch');
    if (noProdMsg) {
        if (foundCount === 0 && query !== "") {
            noProdMsg.classList.remove('d-none');
        } else {
            noProdMsg.classList.add('d-none');
        }
    }
});

// MOTEUR DE RECHERCHE DYNAMIQUE : HISTORIQUE COMMANDES
document.getElementById('commandeSearch').addEventListener('input', function(e) {
    const query = e.target.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.history-item');
    let foundCount = 0;

    rows.forEach(function(item) {
        const idCmd = item.getAttribute('data-id');
        const produit = item.getAttribute('data-produit');
        const statut = item.getAttribute('data-statut');
        const statutRaw = item.getAttribute('data-statutraw');

        if (idCmd.includes(query) || produit.includes(query) || statut.includes(query) || statutRaw.includes(query)) {
            item.classList.remove('d-none');
            foundCount++;
        } else {
            item.classList.add('d-none');
        }
    });

    const noCmdMsg = document.getElementById('noCommandeMatch');
    if (noCmdMsg) {
        if (foundCount === 0 && query !== "") {
            noCmdMsg.classList.remove('d-none');
        } else {
            noCmdMsg.classList.add('d-none');
        }
    }
});

// LOGIQUE DES NOTIFICATIONS
document.addEventListener("DOMContentLoaded", function() {
    chargerNotificationsBadge();
    setInterval(chargerNotificationsBadge, 8000);
});

function chargerNotificationsBadge() {
    fetch('acheteur.php?action=fetch_notifications')
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                const b = document.getElementById('badge-notif');
                if (res.non_lues > 0) {
                    b.innerText = res.non_lues;
                    b.classList.remove('d-none');
                } else {
                    b.classList.add('d-none');
                }
                
                const conteneur = document.getElementById('conteneur-notifs-ajax');
                if (res.notifs.length === 0) {
                    conteneur.innerHTML = '<div class="text-center py-4 text-muted small">Aucune alerte logistique enregistrée.</div>';
                    return;
                }
                
                let html = "";
                res.notifs.forEach(n => {
                    const classeNonLu = (n.statut === 'non_lu') ? 'non-lu' : '';
                    html += `
                        <div class="p-3 mb-2 shadow-sm notif-item ${classeNonLu}">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <span class="fw-semibold text-dark small" style="line-height:1.4;">${escapeHtml(n.message)}</span>
                            </div>
                        </div>`;
                });
                conteneur.innerHTML = html;
            }
        });
}

function escapeHtml(text) {
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function marquerCommeLuDirect() {
    fetch('acheteur.php?action=marquer_lu').then(() => {
        document.getElementById('badge-notif').classList.add('d-none');
    });
}
</script>

</body>
</html>