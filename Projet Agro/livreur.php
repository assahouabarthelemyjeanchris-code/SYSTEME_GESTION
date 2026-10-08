<?php
session_start();
require_once 'config.php';

// =========================================================================
// SÉCURITÉ : Vérifier si l'utilisateur est connecté et possède le rôle livreur
// =========================================================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'livreur') {
    header('Location: index.php');
    exit;
}

$livreur_id = $_SESSION['user_id'];
$succes = "";
$erreur = "";

try {
    // =========================================================================
    // CHARGEMENT DES INFOS DU LIVREUR ET DE SA COOPÉRATIVE (Définit l'Origine)
    // =========================================================================
    $stmt = $pdo->prepare("
        SELECT l.*, p.nom_cooperative, p.ville_origine 
        FROM livreurs l
        JOIN producteurs p ON l.producteur_id = p.id
        WHERE l.id = ?
    ");
    $stmt->execute([$livreur_id]);
    $livreur = $stmt->fetch();

    if (!$livreur) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
    
    $producteur_id = $livreur['producteur_id'];

    // =========================================================================
    // ACTION 1 : CHANGEMENT DE STATUT GLOBAL DU CAMION + NOTIFICATION
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'changer_statut') {
            $nouveau_statut = $_POST['statut'];
            
            $pdo->beginTransaction();
            try {
                // 1. Mise à jour du statut du livreur
                $update = $pdo->prepare("UPDATE livreurs SET statut = ? WHERE id = ?");
                $update->execute([$nouveau_statut, $livreur_id]);
                $livreur['statut'] = $nouveau_statut; // Actualisation immédiate à l'écran

                // 2. Notification automatique pour le producteur lié
                if (!empty($producteur_id)) {
                    $msg = "🚚 Logi-Vivrier : Le chauffeur a mis à jour l'état de son convoi : \"$nouveau_statut\".";
                    $insert_notif = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, statut) 
                        VALUES (?, ?, 'non_lu')
                    ");
                    $insert_notif->execute([$producteur_id, $msg]);
                }

                $pdo->commit();
                $succes = "Le statut de votre convoi a été mis à jour et votre coopérative a été notifiée !";
            } catch (Exception $e_statut) {
                $pdo->rollBack();
                $erreur = "Erreur lors du changement de statut : " . $e_statut->getMessage();
            }
        }
    }

    // =========================================================================
    // ACTION 2 : CONFIRMATION D'UNE LIVRAISON INDIVIDUELLE + NOTIFICATION PRODUCTEUR
    // =========================================================================
    if (isset($_GET['action']) && $_GET['action'] === 'confirmer_livraison') {
        $commande_id = intval($_GET['commande_id']);
        
        $pdo->beginTransaction();
        try {
            // Validation de la remise en main propre
            $stmt_update = $pdo->prepare("UPDATE commandes SET statut_paiement = 'livre' WHERE id = ? ");
            $stmt_update->execute([$commande_id]);

            // Alerte automatique pour le producteur
            $stmt_prod = $pdo->prepare("
                SELECT s.producteur_id 
                FROM commandes c
                JOIN produits p ON c.produit_id = p.id
                JOIN stocks s ON s.produit_id = p.id
                WHERE c.id = ? 
                LIMIT 1
            ");
            $stmt_prod->execute([$commande_id]);
            $producteur_notif_id = $stmt_prod->fetchColumn();

            if ($producteur_notif_id) {
                $msg = "✅ Logi-Vivrier : Le chauffeur confirme la livraison et la remise en main propre de la commande N°$commande_id au grossiste.";
                $insert_notif = $pdo->prepare("
                    INSERT INTO notifications (user_id, message, statut) 
                    VALUES (?, ?, 'non_lu')
                ");
                $insert_notif->execute([$producteur_notif_id, $msg]);
            }

            $pdo->commit();
            $succes = "Livraison validée avec succès ! Le producteur et le grossiste ont été notifiés.";
        } catch (Exception $e_livraison) {
            $pdo->rollBack();
            $erreur = "Impossible de valider cette livraison : " . $e_livraison->getMessage();
        }
    }

    // =========================================================================
    // CHARGEMENT DES FEUILLES DE ROUTE (Commandes validées par le producteur)
    // =========================================================================
    $stmt_c = $pdo->prepare("
        SELECT c.*, p.nom AS produit_nom, p.unite, a.nom_complet AS acheteur_nom, a.telephone AS acheteur_tel, a.quartier, a.ville
        FROM commandes c 
        JOIN produits p ON c.produit_id = p.id 
        JOIN acheteurs a ON c.acheteur_id = a.id
        JOIN stocks s ON c.produit_id = s.produit_id
        WHERE s.producteur_id = ? AND c.statut_paiement IN ('paye', 'livre')
        ORDER BY c.date_livraison_prevue ASC
    ");
    $stmt_c->execute([$producteur_id]);
    $mes_livraisons = $stmt_c->fetchAll();

    // Détermination de la destination dynamique basée sur la première commande non livrée
    $ville_destination = "Abidjan"; // Par défaut si vide
    if (!empty($mes_livraisons)) {
        foreach ($mes_livraisons as $l) {
            if ($l['statut_paiement'] !== 'livre') {
                $ville_destination = $l['ville']; // Prend la ville du grossiste actif
                break;
            }
        }
    }

} catch (PDOException $e) {
    die("Erreur de base de données : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gouro-Market | Espace Chauffeur</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f4f7f6;
            color: #2d3748;
        }
        .app-container {
            max-width: 540px;
            margin: 0 auto;
        }
        .card-custom {
            border: none;
            border-radius: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            transition: all 0.2s ease;
        }
        .bg-gradient-track {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        }
        .btn-phone {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: none;
        }
        .btn-phone:hover {
            background-color: #c8e6c9;
            color: #2e7d32;
        }
        .tracking-wide {
            letter-spacing: 0.05em;
        }
        .search-box {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding-left: 40px;
            transition: all 0.2s;
        }
        .search-box:focus {
            border-color: #22c55e;
            box-shadow: 0 0 0 0.2rem rgba(34, 197, 94, 0.15);
        }
    </style>
</head>
<body>

<div class="container py-3 app-container">
    
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm">
        <div class="d-flex align-items-center">
            <div class="bg-success text-white rounded-3 p-2 me-2">
                <i class="bi bi-truck fs-5"></i>
            </div>
            <div>
                <h6 class="fw-bold m-0 text-success">AgriTech Logistique</h6>
                <small class="text-muted text-truncate d-inline-block" style="max-width: 180px;"> Coop : <?= htmlspecialchars($livreur['nom_cooperative'] ?? 'Inconnue') ?></small>
            </div>
        </div>
        <a href="index.php" class="btn btn-light btn-sm text-danger fw-semibold rounded-3 border">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>

    <?php if(!empty($succes)): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 py-3 small mb-3 d-flex align-items-center" role="alert">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i>
            <div><?= $succes ?></div>
        </div>
    <?php endif; ?>
    
    <?php if(!empty($erreur)): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 py-3 small mb-3 d-flex align-items-center" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <div><?= $erreur ?></div>
        </div>
    <?php endif; ?>

    <div class="bg-white p-3 rounded-4 shadow-sm mb-4 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
            <div class="bg-light text-secondary rounded-circle d-flex align-items-center justify-content-center fw-bold text-uppercase" style="width: 42px; height: 42px; border: 2px solid #fff; box-shadow: 0 0 0 2px #22c55e;">
                <?= substr(htmlspecialchars($livreur['nom_complet'] ?? 'CH'), 0, 2) ?>
            </div>
            <div class="ms-3">
                <h6 class="fw-bold m-0"><?= htmlspecialchars($livreur['nom_complet'] ?? 'Chauffeur') ?></h6>
                <small class="text-muted"><i class="bi bi-telephone-fill small"></i> <?= htmlspecialchars($livreur['telephone'] ?? 'N/A') ?></small>
            </div>
        </div>
        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 small fw-semibold">En Ligne</span>
    </div>

    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2 px-1">
            <span class="fw-bold text-secondary small text-uppercase tracking-wide">Trajet Défini par la Coopérative</span>
            <span class="badge bg-success text-white rounded-pill px-2 py-1 fw-bold font-monospace small" style="font-size: 10px;">FLUX RÉEL</span>
        </div>
        
        <div class="bg-gradient-track text-white p-3 rounded-4 mb-3 shadow">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="text-start">
                    <small class="text-white-50 d-block small text-uppercase">Origine (Coop)</small>
                    <span class="fw-bold fs-5 text-warning">
                        <i class="bi bi-geo-alt-fill"></i> <?= !empty($livreur['ville_origine']) ? htmlspecialchars($livreur['ville_origine']) : 'Région Origine' ?>
                    </span>
                </div>
                <div class="text-center px-2 flex-grow-1">
                    <i class="bi bi-arrow-right fs-4 text-success"></i>
                </div>
                <div class="text-end">
                    <small class="text-white-50 d-block small text-uppercase">Destination (Grossiste)</small>
                    <span class="fw-bold fs-5 text-info">
                        <i class="bi bi-geo-fill"></i> <?= htmlspecialchars($ville_destination) ?>
                    </span>
                </div>
            </div>
            
            <div class="pt-2 border-top border-secondary d-flex justify-content-between align-items-center">
                <span class="small text-white-50">État actuel du convoi :</span>
                <?php 
                    $badge_color = 'bg-secondary';
                    if (isset($livreur['statut'])) {
                        if (strpos($livreur['statut'], 'Attente') !== false) $badge_color = 'bg-warning text-dark';
                        if (strpos($livreur['statut'], 'Prêt') !== false) $badge_color = 'bg-info text-white';
                        if (strpos($livreur['statut'], 'En route') !== false) $badge_color = 'bg-primary text-white';
                        if (strpos($livreur['statut'], 'Arrivé') !== false) $badge_color = 'bg-success text-white';
                    }
                ?>
                <span class="badge <?= $badge_color ?> fw-semibold px-2 py-1.5 rounded-3"><?= htmlspecialchars($livreur['statut'] ?? 'Non défini') ?></span>
            </div>
        </div>

        <div class="bg-white p-3 rounded-4 shadow-sm">
            <form method="POST">
                <input type="hidden" name="action" value="changer_statut">
                <div class="row g-2">
                    <div class="col-8">
                        <select class="form-select form-select-md rounded-3 border-light-subtle bg-light small" name="statut" style="font-size: 14px;">
                            <option value="En attente de chargement" <?= (isset($livreur['statut']) && $livreur['statut'] === 'En attente de chargement') ? 'selected' : '' ?>>📦 En attente de chargement</option>
                            <option value="Chargement plein (100%) - Prêt" <?= (isset($livreur['statut']) && $livreur['statut'] === 'Chargement plein (100%) - Prêt') ? 'selected' : '' ?>>✅ Chargement prêt (100%)</option>
                            <option value="En route" <?= (isset($livreur['statut']) && $livreur['statut'] === 'En route') ? 'selected' : '' ?>>🚚 En route vers destination</option>
                            <option value="Arrivé au marché de gros" <?= (isset($livreur['statut']) && $livreur['statut'] === 'Arrivé au marché de gros') ? 'selected' : '' ?>>🏪 Arrivé au Marché de Gros</option>
                        </select>
                    </div>
                    <div class="col-4">
                        <button class="btn btn-success w-100 h-100 fw-bold rounded-3 small" type="submit" style="font-size: 13px;">Mettre à jour</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="mb-4">
        <span class="fw-bold text-secondary small text-uppercase tracking-wide px-1 d-block mb-2">Commandes à remettre aux grossistes</span>
        
        <div class="position-relative mb-3">
            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
            <input type="text" id="destinationSearch" class="form-control form-control-md search-box bg-white text-dark" placeholder="Rechercher une destination (ex: Cocody, Abidjan...)">
        </div>

        <?php if (empty($mes_livraisons)): ?>
            <div class="text-center py-5 bg-white text-muted rounded-4 border-0 shadow-sm fs-6">
                <i class="bi bi-inbox fs-2 text-light-subtle d-block mb-2"></i>
                Aucune livraison active validée par la coopérative.
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3" id="livraisonsContainer">
                <?php foreach($mes_livraisons as $liv): 
                    $est_livre = ($liv['statut_paiement'] === 'livre');
                ?>
                    <div class="card card-custom p-3 commande-item transition-all <?= $est_livre ? 'bg-light opacity-75' : 'bg-white'; ?>" 
                         data-ville="<?= strtolower(htmlspecialchars($liv['ville'])) ?>" 
                         data-quartier="<?= strtolower(htmlspecialchars($liv['quartier'])) ?>"
                         data-grossiste="<?= strtolower(htmlspecialchars($liv['acheteur_nom'])) ?>">
                        
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6 class="m-0 fw-bold text-dark grossiste-nom"><?= htmlspecialchars($liv['acheteur_nom']) ?></h6>
                                <div class="mt-1">
                                    <span class="badge bg-light text-secondary border rounded-2 px-2 py-1 font-monospace fw-normal">ID-Paiement: #<?= $liv['id'] ?></span>
                                </div>
                            </div>
                            <?php if($est_livre): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1 fw-semibold"><i class="bi bi-check2-all"></i> Remis</span>
                            <?php else: ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1 fw-semibold">À Remettre</span>
                            <?php endif; ?>
                        </div>

                        <div class="p-3 bg-light rounded-4 my-2 small text-dark border border-light-subtle">
                            <div class="mb-2">
                                <i class="bi bi-geo-alt text-danger me-1"></i><strong>Destination :</strong> 
                                <span class="text-secondary target-destination"><?= htmlspecialchars($liv['ville']) ?>, <?= htmlspecialchars($liv['quartier']) ?></span>
                            </div>
                            <div class="mb-0">
                                <i class="bi bi-basket3 text-success me-1"></i><strong>Produit :</strong> 
                                <span class="fw-bold text-success"><?= htmlspecialchars($liv['quantite_commande']) ?> <?= htmlspecialchars($liv['unite']) ?>(s)</span> de <?= htmlspecialchars($liv['produit_nom']) ?>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top border-light">
                            <span class="small text-danger bg-danger-subtle px-2 py-1 rounded-2 fw-semibold" style="font-size: 12px;">
                                <i class="bi bi-calendar-check me-1"></i> Prévu : 
                                <?= !empty($liv['date_livraison_prevue']) ? date('d/m/Y', strtotime($liv['date_livraison_prevue'])) : 'Non spécifiée'; ?>
                            </span>
                            
                            <div class="d-flex gap-2">
                                <a href="tel:<?= $liv['acheteur_tel'] ?>" class="btn btn-phone btn-sm rounded-3 px-2.5 py-1.5" title="Appeler le grossiste">
                                    <i class="bi bi-telephone-fill"></i>
                                </a>

                                <?php if(!$est_livre): ?>
                                    <a href="livreur.php?action=confirmer_livraison&commande_id=<?= $liv['id'] ?>" 
                                       class="btn btn-sm btn-success fw-bold rounded-3 px-3 py-1.5 shadow-sm" 
                                       onclick="return confirm('Voulez-vous confirmer la remise en main propre de cette marchandise au grossiste ?')">
                                         <i class="bi bi-check-lg me-1"></i> Valider Remise
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
            
            <div id="noMatchMessage" class="text-center py-5 bg-white text-muted rounded-4 shadow-sm border-0 d-none fs-6">
                <i class="bi bi-geo-fill fs-2 text-danger opacity-50 d-block mb-2"></i>
                Aucune destination trouvée pour votre recherche.
            </div>
        <?php endif; ?>
    </div>

    <div class="text-center my-4 pt-2">
        <p class="text-muted m-0" style="font-size: 11px; letter-spacing: 0.05em;">AgriTech Logistique • Plateforme Vivrière Sécurisée</p>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.getElementById('destinationSearch').addEventListener('input', function(e) {
    const searchText = e.target.value.toLowerCase().trim();
    const items = document.querySelectorAll('.commande-item');
    let visibleCount = 0;

    items.forEach(function(item) {
        const ville = item.getAttribute('data-ville');
        const quartier = item.getAttribute('data-quartier');
        const grossiste = item.getAttribute('data-grossiste');

        // Vérifie si la recherche correspond soit à la ville, au quartier ou au nom du grossiste
        if (ville.includes(searchText) || quartier.includes(searchText) || grossiste.includes(searchText)) {
            item.classList.remove('d-none');
            visibleCount++;
        } else {
            item.classList.add('d-none');
        }
    });

    // Gestion de l'affichage du message "Aucune destination trouvée"
    const noMatchMsg = document.getElementById('noMatchMessage');
    if (noMatchMsg) {
        if (visibleCount === 0 && searchText !== "") {
            noMatchMsg.classList.remove('d-none');
        } else {
            noMatchMsg.classList.add('d-none');
        }
    }
});
</script>

</body>
</html>