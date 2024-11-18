<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require '../src/vendor/autoload.php';
$app = new \Slim\App;

// Database connection details
$servername = "localhost";
$dbusername = "root";
$dbpassword = "";
$dbname = "library";

// Function to generate a new JWT token
function generateToken($userid) {
    $key = 'server_hack';
    $iat = time();
    $payload = [
        'iss' => 'http://library.org',
        'aud' => 'http://library.com',
        'iat' => $iat,
        'exp' => $iat + 3600, // Token valid for 1 hour
        "data" => array(
            "userid" => $userid
        )
    ];
    return JWT::encode($payload, $key, 'HS256');
}

// Endpoint for registering users
$app->post('/user/register', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $usr = $data->username;
    $pass = $data->password;

    try {
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sql = "INSERT INTO users (username, password) VALUES (:usr, :pass)";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['usr' => $usr, 'pass' => password_hash($pass, PASSWORD_DEFAULT)]);
        
        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }
    $conn = null;

    return $response;
});

// Endpoint for authentication
$app->post('/user/authenticate', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $usr = $data->username;
    $pass = $data->password;

    try {
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sql = "SELECT * FROM users WHERE username=:usr";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['usr' => $usr]);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $data = $stmt->fetch();

        // Verify password
        if ($data && password_verify($pass, $data['password'])) {
            $userid = $data['userid'];
            $jwt = generateToken($userid);
            
            // Store the token in the database
            $sqlToken = "INSERT INTO user_tokens (userid, token) VALUES (:userid, :token)";
            $stmtToken = $conn->prepare($sqlToken);
            $stmtToken->execute(['userid' => $userid, 'token' => $jwt]);
            
            $response->getBody()->write(json_encode(array("status" => "success", "token" => $jwt, "data" => null)));
        } else {
            $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => "Authentication Failed"))));
        }
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }

    return $response;
});

// Endpoint for inserting book and author with token invalidation
$app->post('/book-author/insert', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $bookTitle = $data->bookTitle;
    $authorName = $data->authorName;
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token by checking in the database
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Decode the token after checking validity
        $decoded = JWT::decode($token, new Key('server_hack', 'HS256'));
        $userid = $decoded->data->userid;

        // Insert book
        $sqlBook = "INSERT INTO books (title) VALUES (:title)";
        $stmtBook = $conn->prepare($sqlBook);
        $stmtBook->execute(['title' => $bookTitle]);

        // Insert author
        $sqlAuthor = "INSERT INTO authors (name) VALUES (:name)";
        $stmtAuthor = $conn->prepare($sqlAuthor);
        $stmtAuthor->execute(['name' => $authorName]);

        // Remove the old token from the database
        $sqlDeleteToken = "DELETE FROM user_tokens WHERE userid = :userid AND token = :token";
        $stmtDeleteToken = $conn->prepare($sqlDeleteToken);
        $stmtDeleteToken->execute(['userid' => $userid, 'token' => $token]);

        // Generate a new token and store it in the database
        $newToken = generateToken($userid);
        $sqlInsertNewToken = "INSERT INTO user_tokens (userid, token) VALUES (:userid, :token)";
        $stmtInsertNewToken = $conn->prepare($sqlInsertNewToken);
        $stmtInsertNewToken->execute(['userid' => $userid, 'token' => $newToken]);

        // Return the new token to the user
        $response->getBody()->write(json_encode(array("status" => "success", "newToken" => $newToken)));
    } catch (Exception $e) {
        // Handle token decoding failure or database error
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }

    return $response;
});

$app->put('/book-author/update', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $bookId = $data->bookId; // Assuming you pass bookId for updating the book
    $newBookTitle = $data->newBookTitle; // New title for the book
    $newAuthorName = $data->newAuthorName; // New name for the author
    $authorId = $data->authorId; // Get the authorId from the request body
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token by checking in the database
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Decode the token after checking validity
        $decoded = JWT::decode($token, new Key('server_hack', 'HS256'));
        $userid = $decoded->data->userid;

        // Update book
        $sqlBook = "UPDATE books SET title = :newTitle WHERE bookid = :bookId"; // Corrected to use 'bookid'
        $stmtBook = $conn->prepare($sqlBook);
        $stmtBook->execute(['newTitle' => $newBookTitle, 'bookId' => $bookId]);

        // Update author
        $sqlAuthor = "UPDATE authors SET name = :newName WHERE authorid = :authorId"; // Corrected to use 'authorid'
        $stmtAuthor = $conn->prepare($sqlAuthor);
        $stmtAuthor->execute(['newName' => $newAuthorName, 'authorId' => $authorId]); // Make sure to pass authorId from the request

        // Return success response
        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (Exception $e) {
        // Handle token decoding failure or database error
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }

    return $response;
});

// Endpoint for deleting book and author
$app->delete('/book-author/delete', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $bookId = $data->bookId; // Assuming you pass bookId for deleting the book
    $authorId = $data->authorId; // Assuming you pass authorId for deleting the author
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token by checking in the database
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Decode the token after checking validity
        $decoded = JWT::decode($token, new Key('server_hack', 'HS256'));
        $userid = $decoded->data->userid;

        // Delete the book
        $sqlBook = "DELETE FROM books WHERE bookid = :bookId"; // Using bookid for deletion
        $stmtBook = $conn->prepare($sqlBook);
        $stmtBook->execute(['bookId' => $bookId]);

        // Delete the author
        $sqlAuthor = "DELETE FROM authors WHERE authorid = :authorId"; // Using authorid for deletion
        $stmtAuthor = $conn->prepare($sqlAuthor);
        $stmtAuthor->execute(['authorId' => $authorId]);

        // Return success response
        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (Exception $e) {
        // Handle token decoding failure or database error
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }

    return $response;
});
// Endpoint for inserting a book with token validation
$app->post('/book/insert', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $bookTitle = $data->bookTitle;
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Insert book
        $sqlBook = "INSERT INTO books (title) VALUES (:title)";
        $stmtBook = $conn->prepare($sqlBook);
        $stmtBook->execute(['title' => $bookTitle]);

        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }

    return $response;
});
// Endpoint for updating a book with token validation
$app->put('/book/update', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $bookId = $data->bookId; // Assuming you pass bookId for updating the book
    $newBookTitle = $data->newBookTitle; // New title for the book
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Update book
        $sqlBook = "UPDATE books SET title = :newTitle WHERE bookid = :bookId";
        $stmtBook = $conn->prepare($sqlBook);
        $stmtBook->execute(['newTitle' => $newBookTitle, 'bookId' => $bookId]);

        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }

    return $response;
});
// Endpoint for deleting a book with token validation
$app->delete('/book/delete', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $bookId = $data->bookId; // Assuming you pass bookId for deleting the book
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Delete book
        $sqlBook = "DELETE FROM books WHERE bookid = :bookId";
        $stmtBook = $conn->prepare($sqlBook);
        $stmtBook->execute(['bookId' => $bookId]);

        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }

    return $response;
});
// Endpoint for inserting an author with token validation
$app->post('/author/insert', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $authorName = $data->authorName;
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token by checking in the database
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Decode the token after checking validity
        $decoded = JWT::decode($token, new Key('server_hack', 'HS256'));
        $userid = $decoded->data->userid;

        // Insert author
        $sqlAuthor = "INSERT INTO authors (name) VALUES (:name)";
        $stmtAuthor = $conn->prepare($sqlAuthor);
        $stmtAuthor->execute(['name' => $authorName]);

        // Return success response
        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (Exception $e) {
        // Handle token decoding failure or database error
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }

    return $response;
});
// Endpoint for updating an author with token validation
$app->put('/author/update', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $authorId = $data->authorId; // Assuming you pass authorId for updating
    $newAuthorName = $data->newAuthorName; // New name for the author
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token by checking in the database
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Decode the token after checking validity
        $decoded = JWT::decode($token, new Key('server_hack', 'HS256'));
        $userid = $decoded->data->userid;

        // Update author
        $sqlAuthor = "UPDATE authors SET name = :newName WHERE authorid = :authorId"; // Using authorid
        $stmtAuthor = $conn->prepare($sqlAuthor);
        $stmtAuthor->execute(['newName' => $newAuthorName, 'authorId' => $authorId]);

        // Return success response
        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (Exception $e) {
        // Handle token decoding failure or database error
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }

    return $response;
});
// Endpoint for deleting an author with token validation
$app->delete('/author/delete', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $authorId = $data->authorId; // Assuming you pass authorId for deletion
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token by checking in the database
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Decode the token after checking validity
        $decoded = JWT::decode($token, new Key('server_hack', 'HS256'));
        $userid = $decoded->data->userid;

        // Delete author
        $sqlDeleteAuthor = "DELETE FROM authors WHERE authorid = :authorId";
        $stmtDeleteAuthor = $conn->prepare($sqlDeleteAuthor);
        $stmtDeleteAuthor->execute(['authorId' => $authorId]);

        // Return success response
        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (Exception $e) {
        // Handle token decoding failure or database error
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }

    return $response;
});

// Endpoint for adding a user (admin-only)
$app->post('/user/add', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $username = $data->username;
    $password = $data->password;
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token by checking in the database
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Insert user
        $sql = "INSERT INTO users (username, password) VALUES (:username, :password)";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['username' => $username, 'password' => password_hash($password, PASSWORD_DEFAULT)]);

        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }
    $conn = null;

    return $response;
});

// Endpoint for updating a user (admin-only)
$app->put('/user/update', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $userid = $data->userid; // Assuming you pass userid for updating the user
    $newUsername = $data->newUsername;
    $newPassword = $data->newPassword;
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token by checking in the database
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Update user
        $sql = "UPDATE users SET username = :username, password = :password WHERE userid = :userid";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['username' => $newUsername, 'password' => password_hash($newPassword, PASSWORD_DEFAULT), 'userid' => $userid]);

        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }
    $conn = null;

    return $response;
});

// Endpoint for deleting a user (admin-only)
$app->delete('/user/delete', function (Request $request, Response $response, array $args) use ($servername, $dbusername, $dbpassword, $dbname) {
    $data = json_decode($request->getBody());
    $userid = $data->userid; // Assuming you pass userid for deleting the user
    $token = $request->getHeader('Authorization')[0] ?? '';

    // Remove 'Bearer ' from token
    $token = str_replace('Bearer ', '', $token);

    try {
        // Validate the token by checking in the database
        $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbusername, $dbpassword);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if the token exists in the database
        $sqlCheckToken = "SELECT userid FROM user_tokens WHERE token = :token";
        $stmtCheckToken = $conn->prepare($sqlCheckToken);
        $stmtCheckToken->execute(['token' => $token]);

        if ($stmtCheckToken->rowCount() === 0) {
            return $response->withStatus(401)->write(json_encode(array("status" => "fail", "data" => array("title" => "Token is invalid or has been revoked."))));
        }

        // Delete user
        $sql = "DELETE FROM users WHERE userid = :userid";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['userid' => $userid]);

        $response->getBody()->write(json_encode(array("status" => "success", "data" => null)));
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode(array("status" => "fail", "data" => array("title" => $e->getMessage()))));
    }
    $conn = null;

    return $response;
});


$app->run();
?>