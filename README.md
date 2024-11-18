# Library Management System with JWT

A Library Management System designed for secure and efficient book and author management using JSON Web Tokens (JWT) for authentication. This system allows users to manage their library collection, ensuring only authorized access to CRUD operations, with token rotation for enhanced security. The system supports features like user registration, login, adding and updating books/authors, and performing database reset operations.

With this system, administrators can manage users, while regular users can perform actions like adding and updating books, authors, and more. The application ensures that each token is single-use and rotates after every operation, adding an extra layer of security.

## Table of Contents

1. [Features](#features)
2. [Technologies Used](#technologies-used)
3. [Endpoints, Payloads, and Responses](#endpoints-payloads-responses)  
   - [Register Users](#register-users)  
   - [Authenticate Users](#authenticate-users)  
   - [Insert Books with Author](#insert-books-with-author)  
   - [Update Books with Author](#update-books-with-author)  
   - [Delete Books and Authors](#delete-books-and-authors)  
   - [Insert Users](#insert-users)  
   - [Update Users](#update-users)  
   - [Delete Users](#delete-users)  
   - [Insert Author](#insert-author)  
   - [Update Author](#update-author)  
   - [Delete Author](#delete-author)  
4. [How to Use](#how-to-use)

## Features
1. **User Registration:** Create an account to access the library system.
2. **Token-Based Authentication:** Authenticate to obtain a unique JWT for secure database operations.
3. **Token Rotation:** Each JWT is single-use and replaced after an action, preventing token reuse.
4. **CRUD Operations:**  
   - Insert: Add new books, authors, and users to the database.  
   - Update: Modify existing records for books, authors, and users.  
   - Delete: Remove books, authors, and users.  
   - Retrieve: Access information on stored books, authors, and users.

## Technologies Used
1. Backend: PHP (with Slim Framework)
2. Database: MySQL
3. Authentication: JSON Web Tokens (JWT) with token rotation

## Endpoints, Payloads, and Responses

### Register Users
**Endpoint: POST** `/sermonia_library/public/user/register`  
**Request:**
```
{
  "username":"User Name",
  "password":"username123"
}
```
**Response:**
```
{
  "status": "success",
  "data": null
}
```

### Authenticate Users
**Endpoint: POST** `/sermonia_library/public/user/authenticate`  
**Request:**
```
{
  "username":"User Name",
  "password":"username123"
}
```
**Response:**
```
{
  "status": "success",
  "token": "<generated-token>",
  "data": null
}
```

### Insert Books with Author
**Endpoint: POST** `/sermonia_library/public/book-author/insert`  
**Request:**
```
{
    "bookTitle": "Book Title",
    "authorName": "Author Name"
}
```
**Response:**
```
{
  "status": "success",
  "newToken": "<generated-token>"
}
```

### Update Books with Author
**Endpoint: PUT** `/sermonia_library/public/book-author/update`  
**Request:**
```
{
    "bookId": 5,
    "newBookTitle": "UpdateB",
    "newAuthorName": "UpdateA"
}

```
**Response:**
```
{
  "status": "success",
  "data": null
}
```

### Delete Books and Authors
**Endpoint: DELETE** `/sermonia_library/public/book-author/delete`  
**Request:**
```
{
    "bookId": 4, 
    "authorId": 13
}
```
**Response:**
```
{
  "status": "success", 
  "data": null 
}
```

### Insert Users
**Endpoint: POST** `/sermonia_library/public/user/add`  
**Request:**
```
{
    "username": "third", 
    "password": "second123"
}
```
**Response:**
```
{
  "status": "success", 
  "data": null 
}
```

### Update Users
**Endpoint: PUT** `/sermonia_library/public/user/update`  
**Request:**

```
{
    "userid": "1", 
    "newUsername": "NewU", 
    "newPassword": "NewP"
}
```
**Response:**
```
{
  "status": "success", 
  "data": null 
}
```

### Delete Users
**Endpoint: DELETE** `/sermonia_library/public/user/delete`  
**Request:**
```
{
    "userid": "1" 
}
```
**Response:**
```
{
  "status": "success", 
  "data": null 
}
```

### Insert Author
**Endpoint: POST** `/sermonia_library/public/author/insert`  
**Request:**
```
{
    "authorName": "AuthorN"
}
```
**Response:**
```
{
  "status": "success", 
  "data": null 
}
```

### Update Author
**Endpoint: PUT** `/sermonia_library/public/author/update`  
**Request:**
```
{
    "authorId": 1, 
    "newAuthorName": "NewAuthorN"
}
```
**Response:**
```
{
  "status": "success", 
  "data": null 
}
```

### Delete Author
**Endpoint: DELETE** `/sermonia_library/public/author/delete`  
**Request:**
```
{
    "authorId": "1" 
}
```
**Response:**
```
{
  "status": "success", 
  "data": null 
}
```

### Insert Book
**Endpoint: POST** `/sermonia_library/public/book/insert`  
**Request:**
```
{
    "bookTitle": "Book"
}
```
**Response:**
```
{
  "status": "success", 
  "data": null 
}
```

### Update Book
**Endpoint: PUT** `/sermonia_library/public/book/update`  
**Request:**
```
{
    "bookId": 1, 
    "newBookTitle": "NewBookT"
}
```
**Response:**
```
{
  "status": "success", 
  "data": null 
}
```

### Delete Book
**Endpoint: DELETE** `/sermonia_library/public/book/delete`  
**Request:**
```
{
    "bookId": "6" 
}
```
**Response:**
```
{
  "status": "success", 
  "data": null 
}
```

# How to Use
1. First, ensure that the `sermonia_library.sql` database is imported into your MySQL database.
    - This can be found in the `sermonia_library/database/sermonia_library.sql` file in the GitHub repository
    - Import the file into your MySQL database using the following command:
    ```
    SOURCE /path/to/sermonia_library.sql;
    ```
    Replace `/path/to/sermonia_library.sql` with the actual path where the file is located.
2. Create an Account using the Register Payload:
    - Use the **Register** endpoint to create a new user.
    - Sample payload for registering a user:
    ```
    {
      "username": "Username",
      "password": "Password123"
    }
    ```
3. Authenticate the User using the Authenticate Payload:
    - After registration, authenticate the user to obtain the JWT token.
    - Sample payload for authentication:
    ```
    {
      "username": "jj",
      "password": "jj123"
    }    
    ```
    - The response will contain a <generated-token>.
4. To use the Insert, Update, Display, and Delete Books payloads:
    - Copy the <generated-token> received from the authentication step.
    - Go to the Headers section in your API client (e.g., Postman, Thunderclient).
    - Add **Authorization** in the header and paste the <generated-token> in the value field:
    ```
    Authorization: <generated-token>
    ```
5. Use the payloads, configure them according to your needs, and press SEND in your API client.