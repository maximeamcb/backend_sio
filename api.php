<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false, // passe à true en HTTPS
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_name('APPSESSID');
session_start();

/*
|--------------------------------------------------------------------------
| Security headers
|--------------------------------------------------------------------------
*/
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(int $statusCode, mixed $data): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function buildBaseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    return $scheme . '://' . $host;
}

/*
|--------------------------------------------------------------------------
| Optional access restriction
|--------------------------------------------------------------------------
| Si tu veux réserver la doc API aux admins connectés, décommente ce bloc.
|--------------------------------------------------------------------------
*/
/*
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    http_response_code(403);
    exit('Accès interdit');
}
*/

$baseUrl = buildBaseUrl();

$endpoints = [
    [
        'name' => 'Connexion',
        'route' => '/api/login.php',
        'auth' => 'Public',
        'methods' => [
            'POST' => [
                'description' => 'Authentifie un utilisateur et démarre une session.',
                'params' => [
                    'username' => 'string requis',
                    'password' => 'string requis'
                ],
                'notes' => [
                    'Retourne l’utilisateur connecté si les identifiants sont valides.',
                    'Le cookie de session doit être conservé côté client.',
                    'En frontend fetch, utiliser credentials: "include".'
                ],
                'response' => [
                    'success' => true,
                    'user' => [
                        'id' => 1,
                        'username' => 'admin',
                        'role' => 'admin'
                    ]
                ],
                'curl' => 'curl -X POST '
                    . '-H "Content-Type: application/json" '
                    . '-c cookies.txt '
                    . '-d \'{"username":"admin","password":"MotDePasse123!"}\' '
                    . $baseUrl . '/api/login.php'
            ]
        ]
    ],
    [
        'name' => 'Inscription',
        'route' => '/api/register.php',
        'auth' => 'Public',
        'methods' => [
            'POST' => [
                'description' => 'Crée un nouveau compte utilisateur standard.',
                'params' => [
                    'username' => 'string requis, 3 à 30 caractères',
                    'email' => 'email requis',
                    'password' => 'string requis, minimum 12 caractères avec majuscule, minuscule, chiffre et caractère spécial'
                ],
                'notes' => [
                    'Le rôle est fixé côté serveur à user.',
                    'Le client ne doit pas envoyer de role.'
                ],
                'response' => [
                    'success' => true,
                    'message' => 'Compte créé'
                ],
                'curl' => 'curl -X POST '
                    . '-H "Content-Type: application/json" '
                    . '-d \'{"username":"nouvel_utilisateur","email":"nouveau@example.com","password":"MotDePasse123!"}\' '
                    . $baseUrl . '/api/register.php'
            ]
        ]
    ],
    [
        'name' => 'Messages',
        'route' => '/api/posts.php',
        'auth' => 'Mixte',
        'methods' => [
            'GET' => [
                'description' => 'Retourne la liste des messages publics.',
                'params' => 'Aucun',
                'notes' => [
                    'Limité côté serveur.',
                    'Le contenu est renvoyé au format JSON.'
                ],
                'response' => [
                    [
                        'id' => 1,
                        'content' => 'Bonjour le monde',
                        'created_at' => '2026-03-16 10:00:00',
                        'username' => 'maxime'
                    ]
                ],
                'curl' => 'curl -X GET ' . $baseUrl . '/api/posts.php'
            ],
            'POST' => [
                'description' => 'Crée un nouveau message pour l’utilisateur connecté.',
                'params' => [
                    'content' => 'string requis, maximum 1000 caractères'
                ],
                'notes' => [
                    'Authentification requise.',
                    'L’utilisateur est déterminé par la session, pas par user_id envoyé par le client.',
                    'Le cookie de session doit être envoyé.'
                ],
                'response' => [
                    'success' => true,
                    'message' => 'Message créé',
                    'post' => [
                        'id' => 12,
                        'content' => 'Bonjour',
                        'created_at' => '2026-03-16 10:05:00',
                        'username' => 'maxime'
                    ]
                ],
                'curl' => 'curl -X POST '
                    . '-H "Content-Type: application/json" '
                    . '-b cookies.txt '
                    . '-d \'{"content":"Bonjour le monde !"}\' '
                    . $baseUrl . '/api/posts.php'
            ],
            'DELETE' => [
                'description' => 'Supprime un message par ID si le propriétaire ou un admin effectue la demande.',
                'params' => [
                    'id' => 'entier requis'
                ],
                'notes' => [
                    'Authentification requise.',
                    'Suppression autorisée uniquement pour le propriétaire du post ou un admin.'
                ],
                'response' => [
                    'success' => true,
                    'message' => 'Message supprimé'
                ],
                'curl' => 'curl -X DELETE '
                    . '-H "Content-Type: application/json" '
                    . '-b cookies.txt '
                    . '-d \'{"id":1}\' '
                    . $baseUrl . '/api/posts.php'
            ]
        ]
    ],
    [
        'name' => 'Utilisateurs',
        'route' => '/api/users.php',
        'auth' => 'Admin uniquement',
        'methods' => [
            'GET' => [
                'description' => 'Retourne la liste des utilisateurs.',
                'params' => 'Aucun',
                'notes' => [
                    'Authentification admin requise.'
                ],
                'response' => [
                    [
                        'id' => 1,
                        'username' => 'admin',
                        'email' => 'admin@example.com',
                        'role' => 'admin',
                        'created_at' => '2026-03-16 09:00:00'
                    ]
                ],
                'curl' => 'curl -X GET '
                    . '-b cookies.txt '
                    . $baseUrl . '/api/users.php'
            ],
            'POST' => [
                'description' => 'Crée un nouvel utilisateur via un compte admin.',
                'params' => [
                    'username' => 'string requis',
                    'email' => 'email requis',
                    'password' => 'string requis, minimum 12 caractères',
                    'role' => 'user ou admin'
                ],
                'notes' => [
                    'Authentification admin requise.'
                ],
                'response' => [
                    'success' => true,
                    'message' => 'Utilisateur créé',
                    'user' => [
                        'id' => 5,
                        'username' => 'nouvel_admin',
                        'email' => 'new@example.com',
                        'role' => 'admin',
                        'created_at' => '2026-03-16 10:10:00'
                    ]
                ],
                'curl' => 'curl -X POST '
                    . '-H "Content-Type: application/json" '
                    . '-b cookies.txt '
                    . '-d \'{"username":"nouvel_admin","email":"new@example.com","password":"MotDePasse123!","role":"admin"}\' '
                    . $baseUrl . '/api/users.php'
            ],
            'DELETE' => [
                'description' => 'Supprime un utilisateur par ID via un compte admin.',
                'params' => [
                    'id' => 'entier requis'
                ],
                'notes' => [
                    'Authentification admin requise.',
                    'Un admin ne peut pas supprimer son propre compte connecté.'
                ],
                'response' => [
                    'success' => true,
                    'message' => 'Utilisateur supprimé'
                ],
                'curl' => 'curl -X DELETE '
                    . '-H "Content-Type: application/json" '
                    . '-b cookies.txt '
                    . '-d \'{"id":3}\' '
                    . $baseUrl . '/api/users.php'
            ]
        ]
    ]
];

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    jsonResponse(200, [
        'base_url' => $baseUrl,
        'documentation' => $endpoints
    ]);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Documentation API sécurisée</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            max-width: 1100px;
            margin: 2rem auto;
            padding: 0 1rem;
            background: #f7f7f7;
            color: #222;
        }
        h1 {
            border-bottom: 2px solid #0b57d0;
            padding-bottom: .5rem;
            color: #0b57d0;
        }
        .endpoint {
            background: #fff;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,.08);
            border: 1px solid #e8e8e8;
        }
        .route {
            font-family: monospace;
            font-size: 1.05rem;
            background: #f3f3f3;
            padding: .2rem .5rem;
            border-radius: 4px;
            display: inline-block;
        }
        .method-block {
            margin-top: 1rem;
            padding: 1rem;
            border-left: 4px solid #0b57d0;
            background: #fafafa;
        }
        .method-name {
            font-weight: bold;
            font-size: 1.05rem;
            color: #198754;
        }
        .meta {
            margin-top: .5rem;
            color: #555;
        }
        .curl-block, .json-block {
            background: #1f1f1f;
            color: #f5f5f5;
            padding: 1rem;
            border-radius: 6px;
            overflow-x: auto;
            margin-top: .8rem;
            font-family: monospace;
            font-size: .92rem;
            white-space: pre-wrap;
            word-break: break-word;
        }
        ul {
            margin-top: .4rem;
        }
        code.inline {
            background: #efefef;
            padding: .1rem .35rem;
            border-radius: 4px;
        }
        .badge {
            display: inline-block;
            padding: .2rem .5rem;
            border-radius: 999px;
            font-size: .8rem;
            font-weight: bold;
            margin-left: .6rem;
            background: #ececec;
            color: #333;
        }
    </style>
</head>
<body>
    <h1>Documentation API</h1>
    <p>
        Cette page documente les endpoints disponibles. Pour les routes protégées par session,
        il faut conserver puis renvoyer le cookie de session. En JavaScript frontend, utiliser
        <code class="inline">credentials: "include"</code>. En ligne de commande, utiliser
        <code class="inline">-c cookies.txt</code> puis <code class="inline">-b cookies.txt</code>.
    </p>

    <?php foreach ($endpoints as $endpoint): ?>
        <div class="endpoint">
            <h2>
                <?php echo e($endpoint['name']); ?>
                <span class="badge"><?php echo e($endpoint['auth']); ?></span>
            </h2>

            <div class="route"><?php echo e($endpoint['route']); ?></div>

            <?php foreach ($endpoint['methods'] as $method => $details): ?>
                <div class="method-block">
                    <div class="method-name"><?php echo e($method); ?></div>
                    <div class="meta">
                        <strong>Description :</strong> <?php echo e($details['description']); ?>
                    </div>

                    <div class="meta">
                        <strong>Paramètres :</strong>
                        <?php if (is_array($details['params'])): ?>
                            <ul>
                                <?php foreach ($details['params'] as $param => $desc): ?>
                                    <li><code class="inline"><?php echo e($param); ?></code> : <?php echo e($desc); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <?php echo e((string)$details['params']); ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($details['notes']) && is_array($details['notes'])): ?>
                        <div class="meta">
                            <strong>Notes :</strong>
                            <ul>
                                <?php foreach ($details['notes'] as $note): ?>
                                    <li><?php echo e($note); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="meta"><strong>Exemple de réponse :</strong></div>
                    <div class="json-block"><?php echo e(json_encode($details['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></div>

                    <div class="meta"><strong>Exemple curl :</strong></div>
                    <div class="curl-block"><?php echo e($details['curl']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <hr>
    <p>
        Version JSON de cette documentation :
        <code class="inline">?format=json</code>
    </p>
</body>
</html>