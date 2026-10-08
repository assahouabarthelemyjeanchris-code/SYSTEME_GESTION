<?php
session_start();
require_once 'config.php';

$erreur = "";
$succes = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- 1. INSCRIPTION ---
    if (isset($_POST['action']) && $_POST['action'] === 'inscription') {
        $role = $_POST['role'];
        $nom = trim($_POST['nom_complet']);
        $tel = trim($_POST['telephone']);
        $pass = password_hash($_POST['mot_de_passe'], PASSWORD_BCRYPT);
        $date_naiss = $_POST['date_naissance'];

        try {
            if ($role === 'acheteur') {
                $ville = trim($_POST['ville']);
                $quartier = trim($_POST['quartier']);
                $sql = "INSERT INTO acheteurs (nom_complet, telephone, mot_de_passe, date_naissance, ville, quartier) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nom, $tel, $pass, $date_naiss, $ville, $quartier]);
            } else {
                $coop = trim($_POST['nom_cooperative']) ?: 'Indépendante';
                $ville_orig = trim($_POST['ville_origine']);
                $sql = "INSERT INTO producteurs (nom_complet, nom_cooperative, telephone, mot_de_passe, date_naissance, ville_origine) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nom, $coop, $tel, $pass, $date_naiss, $ville_orig]);
            }
            $succes = "Compte créé avec succès ! Connectez-vous.";
        } catch (PDOException $e) {
            $erreur = "Ce numéro de téléphone est déjà utilisé.";
        }
    }

    // --- 2. CONNEXION ---
    if (isset($_POST['action']) && $_POST['action'] === 'connexion') {
        $telephone_saisi = $_POST['telephone']; 
        $password_saisi = $_POST['mot_de_passe'];

        $stmt = $pdo->prepare("SELECT * FROM producteurs WHERE telephone = ?");
        $stmt->execute([$telephone_saisi]);
        $user = $stmt->fetch();
        $role = 'producteur';

        if (!$user) {
            $stmt = $pdo->prepare("SELECT * FROM acheteurs WHERE telephone = ?");
            $stmt->execute([$telephone_saisi]);
            $user = $stmt->fetch();
            $role = 'acheteur';
        }

        if (!$user) {
            $stmt = $pdo->prepare("SELECT * FROM livreurs WHERE telephone = ?");
            $stmt->execute([$telephone_saisi]);
            $user = $stmt->fetch();
            $role = 'livreur';
        }

        if ($user && password_verify($password_saisi, $user['mot_de_passe'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nom'] = $user['nom_complet'];
            $_SESSION['role'] = $role;

            if ($role === 'producteur') {
                header('Location: producteur.php');
            } elseif ($role === 'acheteur') {
                header('Location: acheteur.php');
            } elseif ($role === 'livreur') {
                header('Location: livreur.php'); 
            }
            exit;
        } else {
            $erreur = "Numéro de téléphone ou mot de passe incorrect.";
        }
    }

    // --- 3. MOT DE PASSE OUBLIÉ : DEMANDE DE CODE ---
    if (isset($_POST['action']) && $_POST['action'] === 'demande_code') {
        $role = $_POST['role'];
        $tel = trim($_POST['telephone']);
        $code = rand(1000, 9999);

        if ($role === 'acheteur') {
            $stmt = $pdo->prepare("UPDATE acheteurs SET code_recuperation = ? WHERE telephone = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE producteurs SET code_recuperation = ? WHERE telephone = ?");
        }
        $stmt->execute([$code, $tel]);
        
        $succes = "📢 [DÉMO JURY] SMS envoyé à $tel. Code de récupération : <strong>$code</strong>";
    }

    // --- 4. MOT DE PASSE OUBLIÉ : VÉRIFICATION ET MISE À JOUR ---
    if (isset($_POST['action']) && $_POST['action'] === 'verif_code') {
        $role = $_POST['role'];
        $tel = trim($_POST['telephone']);
        $code = trim($_POST['code']);
        $nouveau_pass = password_hash($_POST['nouveau_mot_de_passe'], PASSWORD_BCRYPT);

        if ($role === 'acheteur') {
            $stmt = $pdo->prepare("SELECT * FROM acheteurs WHERE telephone = ? AND code_recuperation = ?");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM producteurs WHERE telephone = ? AND code_recuperation = ?");
        }
        $stmt->execute([$tel, $code]);
        $user = $stmt->fetch();

        if ($user) {
            if ($role === 'acheteur') {
                $update = $pdo->prepare("UPDATE acheteurs SET mot_de_passe = ?, code_recuperation = NULL WHERE id = ?");
            } else {
                $update = $pdo->prepare("UPDATE producteurs SET mot_de_passe = ?, code_recuperation = NULL WHERE id = ?");
            }
            $update->execute([$nouveau_pass, $user['id']]);
            $succes = "Mot de passe réinitialisé avec succès ! Connectez-vous.";
        } else {
            $erreur = "Le code de récupération est incorrect.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gouro-Market | Espace Authentification</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-gouro: #198754;
            --accent-orange: #ffc107;
            --bg-light: #f8f9fa;
        }
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .main-card {
            border: none;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.08);
            background: #ffffff;
        }
        .banner-side {
            /* Image Unsplash thématique Logistique / Marché Africain de confiance */
            background: linear-gradient(135deg, rgba(25, 135, 84, 0.92), rgba(10, 60, 35, 0.88)), 
                        url('https://images.unsplash.com/photo-1578575437130-527eed3abbec?auto=format&fit=crop&w=800&q=80');
            background-size: cover;
            background-position: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 3rem;
            color: #ffffff;
        }
        .form-side {
            padding: 3rem;
        }
        .nav-pills .nav-link {
            color: #555;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        .nav-pills .nav-link.active {
            background-color: var(--primary-gouro);
            box-shadow: 0 4px 12px rgba(25, 135, 52, 0.2);
        }
        .concept-box {
            background: var(--bg-light);
            border-left: 4px solid var(--primary-gouro);
            border-radius: 0 12px 12px 0;
            transition: transform 0.2s ease;
        }
        .concept-box:hover {
            transform: translateX(4px);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-gouro);
            box-shadow: 0 0 0 0.25rem rgba(25, 135, 52, 0.15);
        }
        .btn-gouro {
            background-color: var(--primary-gouro);
            color: white;
            border: none;
        }
        .btn-gouro:hover {
            background-color: #146c43;
            color: white;
        }
        .input-group-text {
            background-color: var(--bg-light);
            color: #6c757d;
        }
        @media (max-width: 991.98px) {
            .banner-side {
                padding: 2rem;
                min-height: 250px;
            }
            .form-side {
                padding: 2rem;
            }
        }
    </style>
</head>
<body>

<div class="container my-5">
    <div class="row g-0 main-card max-width-xl mx-auto col-xl-10 offset-xl-1">
        
        <!-- PANNEAU GAUCHE : IDENTITÉ VISUELLE & CONCEPT (DÉMO JURY) -->
        <div class="col-lg-5 banner-side">
            <div>
                <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold small mb-3">
                    <i class="bi bi-star-fill"></i> Projet AgriTech 2026
                </span>
                <h1 class="fw-bold display-6 mb-2"><i class="bi bi-truck"></i> AgriTech</h1>
                <p class="lead opacity-90 fs-6">L'achat groupé prédictif du vivrier en Côte d'Ivoire 🇨🇮</p>
            </div>
            
            <div class="mt-4 d-none d-lg-block">
                <blockquote class="blockquote fs-7 text-white-50 border-start ps-3 mb-0">
                    "Sécuriser les flux d'approvisionnement des marchés d'Abidjan directement depuis les coopératives de l'intérieur."
                </blockquote>
            </div>

            <div class="text-white-50 small pt-3 border-top border-white-10 d-flex justify-content-between">
                <span>Version 1.0.0 (Staging)</span>
                <span><i class="bi bi-hdd-network"></i> Connecté à PDO</span>
            </div>
        </div>

        <!-- PANNEAU DROITE : CONFIGURATION INTERACTIVE -->
        <div class="col-lg-7 form-side d-flex flex-column justify-content-center">
            
            <!-- Messages d'erreur et de succès -->
            <?php if($erreur): ?> 
                <div class="alert alert-danger d-flex align-items-center rounded-3 py-2 small shadow-sm mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><div><?= $erreur ?></div>
                </div> 
            <?php endif; ?>
            
            <?php if($succes): ?> 
                <div class="alert alert-success d-flex align-items-center rounded-3 py-2 small shadow-sm mb-3">
                    <i class="bi bi-check-circle-fill me-2"></i><div><?= $succes ?></div>
                </div> 
            <?php endif; ?>

            <!-- Navigation Onglets -->
            <ul class="nav nav-pills nav-justified p-1 bg-light rounded-3 mb-4 shadow-sm" id="mainTab">
                <li class="nav-item">
                    <button class="nav-link active py-2 small fw-bold" id="concept-tab" data-bs-toggle="tab" data-bs-target="#concept">
                        <i class="bi bi-lightbulb"></i> Vision du Projet
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link py-2 small fw-bold" id="auth-tab" data-bs-toggle="tab" data-bs-target="#auth">
                        <i class="bi bi-shield-lock"></i> Passerelle d'Accès
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                
                <!-- ZONE CONCEPT INTERACTIVE -->
                <div class="tab-pane fade show active" id="concept">
                    <h5 class="fw-bold mb-3 text-dark">Indicateurs Clés de Performance</h5>
                    
                    <div class="concept-box p-3 mb-3 shadow-sm">
                        <h6 class="fw-bold text-success m-0"><i class="bi bi-shield-check"></i> Rapprochement des Marges</h6>
                        <p class="text-muted small m-0 mt-1">Zéro intermédiaire spéculateur. Le prix payé par le grossiste va directement à la coopérative de production.</p>
                    </div>
                    
                    <div class="concept-box p-3 mb-4 shadow-sm">
                        <h6 class="fw-bold text-dark m-0"><i class="bi bi-bar-chart-line text-success"></i> Flux Logistique Logique optimisé</h6>
                        <p class="text-muted small m-0 mt-1">L'algorithme de groupage valide le départ du véhicule uniquement lorsque le seuil de rentabilité de charge est atteint.</p>
                    </div>
                    
                    <button class="btn btn-gouro w-100 py-25 rounded-3 fw-bold shadow-sm" onclick="document.getElementById('auth-tab').click();">
                        Accéder à la simulation <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </div>

                <!-- ZONE FORMULAIRES -->
                <div class="tab-pane fade" id="auth">
                    
                    <nav class="d-flex justify-content-center mb-4 small border-bottom pb-2" id="nav-auth-links">
                        <a class="text-decoration-none me-3 fw-bold text-success border-bottom border-2 border-success pb-2" href="#" id="link-login" onclick="showSection('login')">Connexion</a>
                        <a class="text-decoration-none ms-3 fw-bold text-muted pb-2" href="#" id="link-register" onclick="showSection('register')">Créer un compte</a>
                    </nav>

                    <!-- FORMULAIRE : CONNEXION -->
                    <div id="sec-login">
                        <form method="POST">
                            <input type="hidden" name="action" value="connexion">
                            
                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-secondary">Profil Utilisateur</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                    <select class="form-select" name="role" required>
                                        <option value="acheteur">Acheteur Grossiste (Abidjan)</option>
                                        <option value="producteur">Producteur / Coopérative (Intérieur)</option>
                                        <option value="livreur">Livreur / Chauffeur Routier</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-secondary">Numéro de Téléphone</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                    <input type="tel" name="telephone" class="form-control" placeholder="Ex : 0707070707" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-secondary">Mot de passe</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" name="mot_de_passe" class="form-control" required>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-gouro w-100 py-2 rounded-3 fw-bold mb-3 shadow-sm">
                                <i class="bi bi-box-arrow-in-right"></i> Valider l'authentification
                            </button>
                            <div class="text-center"><a href="#" class="small text-muted text-decoration-none" onclick="showSection('forgot')"><i class="bi bi-question-circle"></i> Identifiants oubliés ?</a></div>
                        </form>
                    </div>

                    <!-- FORMULAIRE : INSCRIPTION -->
                    <div id="sec-register" class="d-none">
                        <form method="POST">
                            <input type="hidden" name="action" value="inscription">
                            
                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">Type de compte</label>
                                <select class="form-select form-select-sm" name="role" id="reg-role" onchange="adaptFields()" required>
                                    <option value="acheteur">Acheteur Grossiste (Abidjan)</option>
                                    <option value="producteur">Producteur (Intérieur)</option>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">Nom complet</label>
                                <input type="text" name="nom_complet" class="form-control form-control-sm" required>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">Téléphone</label>
                                <input type="tel" name="telephone" class="form-control form-control-sm" placeholder="Numéro unique" required>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">Date de naissance</label>
                                <input type="date" name="date_naissance" class="form-control form-control-sm" required>
                            </div>
                            
                            <!-- CHAMPS DYNAMIQUES ACHETEUR -->
                            <div id="fields-acheteur" class="p-2 bg-light rounded-3 mb-2 border">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold text-success">Ville de livraison</label>
                                    <input type="text" name="ville" class="form-control form-control-sm" value="Abidjan">
                                </div>
                                <div class="mb-1">
                                    <label class="form-label small fw-semibold text-success">Quartier (Marché cible)</label>
                                    <input type="text" name="quartier" class="form-control form-control-sm" placeholder="Ex: Adjamé, Marcory">
                                </div>
                            </div>

                            <!-- CHAMPS DYNAMIQUES PRODUCTEUR -->
                            <div id="fields-producteur" class="d-none p-2 bg-light rounded-3 mb-2 border">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold text-warning">Structure / Coopérative</label>
                                    <input type="text" name="nom_cooperative" class="form-control form-control-sm" placeholder="Ex: COVIMA">
                                </div>
                                <div class="mb-1">
                                    <label class="form-label small fw-semibold text-warning">Zone de collecte (Origine)</label>
                                    <input type="text" name="ville_origine" class="form-control form-control-sm" placeholder="Ex: Bouaflé, Korhogo">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-secondary">Créer un mot de passe</label>
                                <input type="password" name="mot_de_passe" class="form-control form-control-sm" required>
                            </div>

                            <button type="submit" class="btn btn-warning w-100 py-2 text-white fw-bold shadow-sm">
                                <i class="bi bi-person-plus"></i> Enregistrer le profil
                            </button>
                        </form>
                    </div>

                    <!-- FORMULAIRE : MOT DE PASSE OUBLIÉ -->
                    <div id="sec-forgot" class="d-none">
                        <h6 class="fw-bold mb-3 small" style="cursor:pointer;" onclick="showSection('login')">
                            <i class="bi bi-arrow-left-short fs-5 align-middle"></i> Retour à l'écran de connexion
                        </h6>
                        
                        <!-- Étape 1 -->
                        <form method="POST" class="mb-3 bg-light p-3 rounded-3 border">
                            <input type="hidden" name="action" value="demande_code">
                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">Votre Rôle</label>
                                <select class="form-select form-select-sm" name="role" required>
                                    <option value="acheteur">Acheteur Grossiste</option>
                                    <option value="producteur">Producteur</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">Téléphone lié au compte</label>
                                <input type="tel" name="telephone" class="form-control form-control-sm" required>
                            </div>
                            <button type="submit" class="btn btn-secondary btn-sm w-100 fw-bold">Générer le jeton SMS (Démo)</button>
                        </form>

                        <!-- Étape 2 -->
                        <form method="POST" class="border-top pt-3">
                            <input type="hidden" name="action" value="verif_code">
                            <div class="row g-2">
                                <div class="col-6 mb-2">
                                    <label class="form-label small fw-semibold text-secondary">Rôle</label>
                                    <select class="form-select form-select-sm" name="role"><option value="acheteur">Acheteur</option><option value="producteur">Producteur</option></select>
                                </div>
                                <div class="col-6 mb-2">
                                    <label class="form-label small fw-semibold text-secondary">Téléphone</label>
                                    <input type="tel" name="telephone" class="form-control form-control-sm" required>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold text-secondary">Code OTP reçu</label>
                                <input type="text" name="code" class="form-control form-control-sm" placeholder="4 chiffres" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-secondary">Nouveau mot de passe</label>
                                <input type="password" name="nouveau_mot_de_passe" class="form-control form-control-sm" required>
                            </div>
                            <button type="submit" class="btn btn-gouro btn-sm w-100 fw-bold">Appliquer la réinitialisation</button>
                        </form>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<script>
function showSection(section) {
    // Éléments de structure
    document.getElementById('sec-login').classList.add('d-none');
    document.getElementById('sec-register').classList.add('d-none');
    document.getElementById('sec-forgot').classList.add('d-none');
    document.getElementById('nav-auth-links').classList.remove('d-none');
    
    // Éléments de navigation (classes actives)
    const lblLogin = document.getElementById('link-login');
    const lblRegister = document.getElementById('link-register');
    
    lblLogin.className = "text-decoration-none me-3 fw-bold text-muted pb-2";
    lblRegister.className = "text-decoration-none ms-3 fw-bold text-muted pb-2";

    if(section === 'login') {
        document.getElementById('sec-login').classList.remove('d-none');
        lblLogin.className = "text-decoration-none me-3 fw-bold text-success border-bottom border-2 border-success pb-2";
    } else if(section === 'register') {
        document.getElementById('sec-register').classList.remove('d-none');
        lblRegister.className = "text-decoration-none ms-3 fw-bold text-success border-bottom border-2 border-success pb-2";
    } else if(section === 'forgot') {
        document.getElementById('sec-forgot').classList.remove('d-none');
        document.getElementById('nav-auth-links').classList.add('d-none');
    }
}

function adaptFields() {
    const role = document.getElementById('reg-role').value;
    document.getElementById('fields-acheteur').classList.toggle('d-none', role !== 'acheteur');
    document.getElementById('fields-producteur').classList.toggle('d-none', role !== 'producteur');
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>