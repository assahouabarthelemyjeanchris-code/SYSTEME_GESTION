CREATE DATABASE gouro_market DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gouro_market;

-- =========================================================================
-- 1. TABLE DES PRODUCTEURS (Mamans Gouro / Coopératives de l'intérieur)
-- =========================================================================
CREATE TABLE IF NOT EXISTS producteurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom_complet VARCHAR(100) NOT NULL,
    nom_cooperative VARCHAR(100) DEFAULT 'Indépendante',
    telephone VARCHAR(20) NOT NULL UNIQUE,
    mot_de_passe VARCHAR(255) NOT NULL,
    date_naissance DATE NOT NULL,          -- Ajouté pour la création de compte
    ville_origine VARCHAR(50) NOT NULL,    -- Ex: Bouaflé, Korhogo, Ferké
    date_inscription TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================================================
-- 2. TABLE DES ACHETEURS GROSSISTES (Restauratrices / Familles d'Abidjan)
-- =========================================================================
CREATE TABLE IF NOT EXISTS acheteurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom_complet VARCHAR(100) NOT NULL,
    telephone VARCHAR(20) NOT NULL UNIQUE,
    mot_de_passe VARCHAR(255) NOT NULL,
    date_naissance DATE NOT NULL,          -- Ajouté pour la création de compte
    ville VARCHAR(50) NOT NULL,            -- Ajouté (Ex: Abidjan)
    quartier VARCHAR(50) NOT NULL,         -- Ajouté (Ex: Marcory, Yopougon Selmer)
    date_inscription TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================================================
-- 3. TABLE DES PRODUITS DE RÉFÉRENCE (Le catalogue général fixe)
-- =========================================================================
CREATE TABLE IF NOT EXISTS produits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(50) NOT NULL,
    prix_marche_unitaire INT NOT NULL,     -- Prix indicatif au marché pour le calcul d'économies
    unite VARCHAR(20) DEFAULT 'sac'        -- sac, panier, régime
) ENGINE=InnoDB;

-- =========================================================================
-- 4. TABLE DES STOCKS DES PRODUCTEURS (Pour le CRUD de chaque producteur)
-- =========================================================================
CREATE TABLE IF NOT EXISTS stocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producteur_id INT NOT NULL,
    produit_id INT NOT NULL,
    quantite_dispo INT NOT NULL DEFAULT 0, -- Nombre d'unités que le producteur possède
    prix_demande INT NOT NULL,             -- Le prix fixé par ce producteur
    derniere_maj TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (producteur_id) REFERENCES producteurs(id) ON DELETE CASCADE,
    FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================================================
-- 5. TABLE DES CAMIONS DE GROUPAGE (L'innovation Achat Groupé Prédictif)
-- =========================================================================
CREATE TABLE IF NOT EXISTS camions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provenance VARCHAR(50) NOT NULL,       -- Ex: Korhogo
    destination VARCHAR(50) NOT NULL,      -- Ex: Abidjan (Gare UTB Adjamé)
    capacite_max INT NOT NULL,             -- Objectif total de sacs pour le départ
    statut ENUM('en_collecte', 'pret_a_partir', 'en_route') DEFAULT 'en_collecte'
) ENGINE=InnoDB;

-- =========================================================================
-- 6. TABLE DES COMMANDES (Réservations des Acheteurs Grossistes)
-- =========================================================================
CREATE TABLE IF NOT EXISTS commandes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    acheteur_id INT NOT NULL,
    camion_id INT NOT NULL,
    produit_id INT NOT NULL,
    quantite_commande INT NOT NULL,
    statut_paiement ENUM('en_attente', 'paye') DEFAULT 'en_attente',
    date_commande TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (acheteur_id) REFERENCES acheteurs(id) ON DELETE CASCADE,
    FOREIGN KEY (camion_id) REFERENCES camions(id) ON DELETE CASCADE,
    FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- =========================================================================
-- 🚀 JEU DE DONNÉES TESTS MIS À JOUR POUR TA DÉMONSTRATION
-- =========================================================================

-- Injection des 6 produits vivriers demandés
INSERT INTO produits (nom, prix_marche_unitaire, unite) VALUES
('Piment Frais', 18000, 'sac'),
('Tomates Ration', 22000, 'panier'),
('Aubergines', 11000, 'sac'),
('Banane Plantain', 14000, 'régime'),
('Oignons Violine', 15000, 'sac'),
('Gombo Frais', 12000, 'panier');

-- Création d'un compte Acheteur et d'un compte Producteur de test (Mot de passe pour les deux : "123456")
INSERT INTO acheteurs (nom_complet, telephone, mot_de_passe, date_naissance, ville, quartier) VALUES 
('Tantie Tantou', '0707070707', '$2y$10$mCgX9Kj5iBwMREgK6eK6e.Wp3wWjU6YmE02wA19G9oI9vMvY3mC2.', '1985-06-15', 'Abidjan', 'Marcory Zone 4');

INSERT INTO producteurs (nom_complet, nom_cooperative, telephone, mot_de_passe, date_naissance, ville_origine) VALUES 
('Maman Awa', 'Coopérative Djigui de Korhogo', '0505050505', '$2y$10$mCgX9Kj5iBwMREgK6eK6e.Wp3wWjU6YmE02wA19G9oI9vMvY3mC2.', '1978-11-22', 'Korhogo');

-- Maman Awa (Producteur ID: 1) ajoute ses stocks de départ
INSERT INTO stocks (producteur_id, produit_id, quantite_dispo, prix_demande) VALUES
(1, 1, 60, 10000), 
(1, 2, 40, 12000);

-- Initialisation d'un camion virtuel en attente de chargement au départ de Korhogo
INSERT INTO camions (provenance, destination, capacite_max, statut) VALUES
('Korhogo', 'Abidjan (Gare UTB Adjamé)', 100, 'en_collecte');

-- Simulation d'une commande initiale par Tantie Tantou (Acheteur ID: 1) dans le camion 1
INSERT INTO commandes (acheteur_id, camion_id, produit_id, quantite_commande, statut_paiement) VALUES
(1, 1, 1, 45, 'paye');
-- Ajout des champs de récupération pour les producteurs
ALTER TABLE producteurs ADD COLUMN code_recuperation VARCHAR(10) DEFAULT NULL;

-- Ajout des champs de récupération pour les acheteurs
ALTER TABLE acheteurs ADD COLUMN code_recuperation VARCHAR(10) DEFAULT NULL;
CREATE TABLE IF NOT EXISTS livreurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producteur_id INT NOT NULL, -- Lie le livreur à son producteur/coopérative
    nom_complet VARCHAR(255) NOT NULL,
    telephone VARCHAR(20) NOT NULL UNIQUE,
    mot_de_passe VARCHAR(255) NOT NULL,
    date_naissance DATE NOT NULL,
    statut VARCHAR(50) DEFAULT 'En attente de chargement', -- Statut pour le suivi du camion
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producteur_id) REFERENCES producteurs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 1. Ajout des champs logistiques pour le point de départ et la destination dans les stocks
ALTER TABLE `stocks` 
ADD COLUMN `point_depart` VARCHAR(100) NOT NULL DEFAULT 'Yamoussoukro' AFTER `prix_demande`,
ADD COLUMN `destination` VARCHAR(100) NOT NULL DEFAULT 'Abidjan' AFTER `point_depart`;

-- 2. Sécurité/Mise à jour pour la table commandes (si ce n'est pas déjà fait)
-- On s'assure que le champ statut_paiement accepte la valeur 'annule'
ALTER TABLE `commandes` 
MODIFY COLUMN `statut_paiement` ENUM('en_attente', 'paye', 'annule') NOT NULL DEFAULT 'en_attente';