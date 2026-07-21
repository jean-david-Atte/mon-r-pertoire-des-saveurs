const express = require('express');
const mysql = require('mysql2');
const cors = require('cors');
const bcrypt = require('bcryptjs');
const app = express();

app.use(cors());
app.use(express.json());

const db = mysql.createConnection({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'repertoire_saveurs'
});

db.connect(err => {
    if (err) {
        console.error('Erreur DB:', err);
        process.exit(1);
    }
    console.log('✅ Connecté à MySQL');
});

function formaterPlat(plat) {
    return new Promise((resolve, reject) => {
        db.query(
            `SELECT o.prix, off.nom AS offrant_nom, off.quartier, off.lien
             FROM offres o
             JOIN offrants off ON off.id = o.offrant_id
             WHERE o.plat_id = ?`,
            [plat.id],
            (err, offres) => {
                if (err) return reject(err);
                resolve({
                    id: plat.id,
                    nom: plat.nom,
                    description: plat.description,
                    origine: plat.origine,
                    categorie: plat.categorie,
                    note: parseFloat(plat.note),
                    image_url: plat.image_url,
                    offres: offres
                });
            }
        );
    });
}

// ---------- AUTHENTIFICATION ----------
app.post('/api/connexion', (req, res) => {
    const { email, mot_passe } = req.body;
    if (!email || !mot_passe) {
        return res.status(400).json({ erreur: 'Email et mot de passe requis' });
    }
    db.query('SELECT * FROM utilisateurs WHERE email = ?', [email], (err, results) => {
        if (err) return res.status(500).json({ erreur: err.message });
        if (results.length === 0) {
            return res.status(401).json({ erreur: 'Email ou mot de passe incorrect' });
        }
        const utilisateur = results[0];
        const isValid = bcrypt.compareSync(mot_passe, utilisateur.mot_de_passe);
        if (!isValid) {
            return res.status(401).json({ erreur: 'Email ou mot de passe incorrect' });
        }
        delete utilisateur.mot_de_passe;
        res.json({ succes: true, utilisateur });
    });
});

app.post('/api/inscription', (req, res) => {
    const { nom, email, mot_passe } = req.body;
    if (!email || !mot_passe) {
        return res.status(400).json({ erreur: 'Email et mot de passe requis' });
    }
    db.query('SELECT id FROM utilisateurs WHERE email = ?', [email], (err, results) => {
        if (err) return res.status(500).json({ erreur: err.message });
        if (results.length > 0) {
            return res.status(400).json({ erreur: 'Cet email est déjà utilisé' });
        }
        const hash = bcrypt.hashSync(mot_passe, 10);
        db.query(
            'INSERT INTO utilisateurs (email, mot_de_passe, nom) VALUES (?, ?, ?)',
            [email, hash, nom || ''],
            (err) => {
                if (err) return res.status(500).json({ erreur: err.message });
                res.json({ succes: true, message: 'Compte créé avec succès' });
            }
        );
    });
});

app.post('/api/reinitialisation', (req, res) => {
    const { email } = req.body;
    if (!email) {
        return res.status(400).json({ erreur: 'Email requis' });
    }
    db.query('SELECT id FROM utilisateurs WHERE email = ?', [email], (err, results) => {
        if (err) return res.status(500).json({ erreur: err.message });
        if (results.length === 0) {
            return res.status(404).json({ erreur: 'Email introuvable' });
        }
        res.json({ succes: true, message: 'Lien de réinitialisation envoyé' });
    });
});

// ---------- FAVORIS ----------
app.get('/api/favoris_plats', (req, res) => {
    const utilisateur_id = parseInt(req.query.utilisateur_id);
    if (!utilisateur_id || utilisateur_id <= 0) {
        return res.status(400).json({ erreur: 'ID utilisateur requis' });
    }
    db.query(
        `SELECT p.* FROM favoris_plats fp 
         JOIN plats p ON p.id = fp.plat_id 
         WHERE fp.utilisateur_id = ?`,
        [utilisateur_id],
        async (err, results) => {
            if (err) return res.status(500).json({ erreur: err.message });
            try {
                const favoris = await Promise.all(results.map(p => formaterPlat(p)));
                res.json({ favoris });
            } catch (error) {
                res.status(500).json({ erreur: error.message });
            }
        }
    );
});

app.get('/api/favoris_restaurants', (req, res) => {
    const utilisateur_id = parseInt(req.query.utilisateur_id);
    if (!utilisateur_id || utilisateur_id <= 0) {
        return res.status(400).json({ erreur: 'ID utilisateur requis' });
    }
    db.query(
        `SELECT off.* FROM favoris_restaurants fr 
         JOIN offrants off ON off.id = fr.offrant_id 
         WHERE fr.utilisateur_id = ?`,
        [utilisateur_id],
        (err, results) => {
            if (err) return res.status(500).json({ erreur: err.message });
            res.json({ favoris: results });
        }
    );
});

app.post('/api/ajouter_favori_plat', (req, res) => {
    const { utilisateur_id, plat_id } = req.body;
    if (!utilisateur_id || !plat_id) {
        return res.status(400).json({ erreur: 'Données manquantes' });
    }
    db.query(
        'INSERT INTO favoris_plats (utilisateur_id, plat_id) VALUES (?, ?)',
        [utilisateur_id, plat_id],
        (err) => {
            if (err) {
                if (err.code === 'ER_DUP_ENTRY') {
                    return res.status(400).json({ erreur: 'Déjà dans les favoris' });
                }
                return res.status(500).json({ erreur: err.message });
            }
            res.json({ succes: true, message: 'Ajouté aux favoris' });
        }
    );
});

app.delete('/api/supprimer_favori_plat', (req, res) => {
    const utilisateur_id = parseInt(req.query.utilisateur_id);
    const plat_id = parseInt(req.query.plat_id);
    if (!utilisateur_id || !plat_id) {
        return res.status(400).json({ erreur: 'Paramètres invalides' });
    }
    db.query(
        'DELETE FROM favoris_plats WHERE utilisateur_id = ? AND plat_id = ?',
        [utilisateur_id, plat_id],
        (err) => {
            if (err) return res.status(500).json({ erreur: err.message });
            res.json({ succes: true, message: 'Retiré des favoris' });
        }
    );
});

app.post('/api/ajouter_favori_restaurant', (req, res) => {
    const { utilisateur_id, offrant_id } = req.body;
    if (!utilisateur_id || !offrant_id) {
        return res.status(400).json({ erreur: 'Données manquantes' });
    }
    db.query(
        'INSERT INTO favoris_restaurants (utilisateur_id, offrant_id) VALUES (?, ?)',
        [utilisateur_id, offrant_id],
        (err) => {
            if (err) {
                if (err.code === 'ER_DUP_ENTRY') {
                    return res.status(400).json({ erreur: 'Déjà dans les favoris' });
                }
                return res.status(500).json({ erreur: err.message });
            }
            res.json({ succes: true, message: 'Ajouté aux favoris' });
        }
    );
});

app.delete('/api/supprimer_favori_restaurant', (req, res) => {
    const utilisateur_id = parseInt(req.query.utilisateur_id);
    const offrant_id = parseInt(req.query.offrant_id);
    if (!utilisateur_id || !offrant_id) {
        return res.status(400).json({ erreur: 'Paramètres invalides' });
    }
    db.query(
        'DELETE FROM favoris_restaurants WHERE utilisateur_id = ? AND offrant_id = ?',
        [utilisateur_id, offrant_id],
        (err) => {
            if (err) return res.status(500).json({ erreur: err.message });
            res.json({ succes: true, message: 'Retiré des favoris' });
        }
    );
});

// ---------- PLATS ----------
app.get('/api/recherche', (req, res) => {
    const terme = req.query.q || '';
    if (terme === '') {
        return res.json({ resultats: [] });
    }
    db.query(
        'SELECT * FROM plats WHERE nom LIKE ? ORDER BY note DESC LIMIT 20',
        [`%${terme}%`],
        async (err, results) => {
            if (err) return res.status(500).json({ erreur: err.message });
            try {
                const resultats = await Promise.all(results.map(p => formaterPlat(p)));
                res.json({ resultats });
            } catch (error) {
                res.status(500).json({ erreur: error.message });
            }
        }
    );
});

app.get('/api/aleatoire', (req, res) => {
    db.query('SELECT * FROM plats ORDER BY RAND() LIMIT 8', async (err, results) => {
        if (err) return res.status(500).json({ erreur: err.message });
        try {
            const resultats = await Promise.all(results.map(p => formaterPlat(p)));
            res.json({ resultats });
        } catch (error) {
            res.status(500).json({ erreur: error.message });
        }
    });
});

app.get('/api/trier', (req, res) => {
    const critere = req.query.critere || 'nom';
    const ordre = req.query.ordre || 'ASC';
    const limite = parseInt(req.query.limite) || 30;
    const criteresAutorises = ['nom', 'note', 'origine', 'categorie', 'id'];
    if (!criteresAutorises.includes(critere)) {
        return res.status(400).json({ erreur: 'Critère invalide' });
    }
    const ordreSQL = ordre.toUpperCase() === 'DESC' ? 'DESC' : 'ASC';
    db.query(
        `SELECT * FROM plats ORDER BY ${critere} ${ordreSQL} LIMIT ?`,
        [limite],
        async (err, results) => {
            if (err) return res.status(500).json({ erreur: err.message });
            try {
                const resultats = await Promise.all(results.map(p => formaterPlat(p)));
                res.json({ resultats, tri: { critere, ordre: ordreSQL } });
            } catch (error) {
                res.status(500).json({ erreur: error.message });
            }
        }
    );
});

app.get('/api/filtrer', (req, res) => {
    const type = req.query.type;
    const valeur = req.query.valeur;
    if (!type || !valeur) {
        return res.status(400).json({ erreur: 'Filtre requis' });
    }
    if (!['categorie', 'origine'].includes(type)) {
        return res.status(400).json({ erreur: 'Type invalide' });
    }
    db.query(
        `SELECT * FROM plats WHERE ${type} = ? ORDER BY nom ASC`,
        [valeur],
        async (err, results) => {
            if (err) return res.status(500).json({ erreur: err.message });
            try {
                const resultats = await Promise.all(results.map(p => formaterPlat(p)));
                res.json({ resultats, filtre: { type, valeur } });
            } catch (error) {
                res.status(500).json({ erreur: error.message });
            }
        }
    );
});

app.get('/api/categories', (req, res) => {
    db.query('SELECT DISTINCT categorie FROM plats ORDER BY categorie', (err, results) => {
        if (err) return res.status(500).json({ erreur: err.message });
        res.json({ categories: results.map(r => r.categorie) });
    });
});

app.get('/api/origines', (req, res) => {
    db.query('SELECT DISTINCT origine FROM plats ORDER BY origine', (err, results) => {
        if (err) return res.status(500).json({ erreur: err.message });
        res.json({ origines: results.map(r => r.origine) });
    });
});

app.get('/api/detail', (req, res) => {
    const id = parseInt(req.query.id);
    if (!id) {
        return res.status(400).json({ erreur: 'ID requis' });
    }
    db.query('SELECT * FROM plats WHERE id = ?', [id], async (err, results) => {
        if (err) return res.status(500).json({ erreur: err.message });
        if (results.length === 0) {
            return res.status(404).json({ erreur: 'Plat introuvable' });
        }
        try {
            const plat = await formaterPlat(results[0]);
            res.json({ resultat: plat });
        } catch (error) {
            res.status(500).json({ erreur: error.message });
        }
    });
});

app.post('/api/ajouter', (req, res) => {
    const { nom, description, origine, categorie, note, image_url } = req.body;
    const champsRequis = ['nom', 'description', 'origine', 'categorie', 'note', 'image_url'];
    for (const champ of champsRequis) {
        if (!req.body[champ]) {
            return res.status(400).json({ erreur: `Le champ '${champ}' est requis` });
        }
    }
    db.query(
        `INSERT INTO plats (nom, description, origine, categorie, note, image_url) 
         VALUES (?, ?, ?, ?, ?, ?)`,
        [nom, description, origine, categorie, parseFloat(note), image_url],
        (err, result) => {
            if (err) return res.status(500).json({ erreur: err.message });
            const nouvelId = result.insertId;
            db.query('SELECT * FROM plats WHERE id = ?', [nouvelId], async (err, results) => {
                if (err) return res.status(500).json({ erreur: err.message });
                try {
                    const plat = await formaterPlat(results[0]);
                    res.json({ succes: true, message: 'Plat ajouté', plat });
                } catch (error) {
                    res.status(500).json({ erreur: error.message });
                }
            });
        }
    );
});

app.delete('/api/supprimer', (req, res) => {
    const id = parseInt(req.query.id);
    if (!id || id <= 0) {
        return res.status(400).json({ erreur: 'ID invalide' });
    }
    db.query('SELECT id FROM plats WHERE id = ?', [id], (err, results) => {
        if (err) return res.status(500).json({ erreur: err.message });
        if (results.length === 0) {
            return res.status(404).json({ erreur: 'Plat non trouvé' });
        }
        db.query('DELETE FROM plats WHERE id = ?', [id], (err) => {
            if (err) return res.status(500).json({ erreur: err.message });
            res.json({ succes: true, message: 'Plat supprimé' });
        });
    });
});

// ---------- RESTAURANTS ----------
app.get('/api/restaurants', (req, res) => {
    db.query(`SELECT 
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
    FROM offrants`, (err, results) => {
        if (err) return res.status(500).json({ erreur: err.message });
        let count = 0;
        const restaurants = results.map(r => {
            db.query(
                'SELECT COUNT(*) as total FROM offres WHERE offrant_id = ?',
                [r.id],
                (err, countResult) => {
                    count++;
                    r.nb_plats = parseInt(countResult[0].total);
                    if (count === results.length) {
                        res.json({ restaurants });
                    }
                }
            );
            return r;
        });
        if (results.length === 0) {
            res.json({ restaurants: [] });
        }
    });
});

app.get('/api/restaurant_plats', (req, res) => {
    const id = parseInt(req.query.id);
    if (!id || id <= 0) {
        return res.status(400).json({ erreur: 'ID invalide' });
    }
    db.query(
        `SELECT p.id, p.nom, p.categorie, o.prix 
         FROM offres o 
         JOIN plats p ON p.id = o.plat_id 
         WHERE o.offrant_id = ? 
         ORDER BY o.prix ASC`,
        [id],
        (err, results) => {
            if (err) return res.status(500).json({ erreur: err.message });
            res.json({ plats: results });
        }
    );
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    console.log(`✅ Serveur démarré sur le port ${PORT}`);
});

module.exports = app;