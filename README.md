# Authentication and User Management API Documentation

## Base URL

http://your-api-url.com/api

This document provides details for the authentication and user creation/update endpoints.

---

## Endpoints

### 1. **User Login**

**Endpoint:**  
`POST /login`

**Description:**  
Authenticates a user with their email and password, returning a token upon successful login.

**Request Headers:**
```
{
    "Content-Type": "application/json"
}
```
Request Body:

```
{
    "email": "user@example.com",
    "password": "password123"
}
```
Response:

Success (200):
```
{
    "status": true,
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiIsInR...",
    "message": "Login Successful"
}
```
Unauthorized (401):
```
{
    "status": false,
    "message": "Unauthorized"
}
```
Error (500):
```
{
    "status": false,
    "message": "An error occurred: Error details here"
}
```

# Designation API Documentation

This API allows you to manage designations. You can create, update, and delete designations.

## Base URL

http://your-api-url.com/api

## Authentication

All endpoints require proper authentication. Include the authentication token in the `Authorization` header:

## Authorization: Bearer (token)

---

## Endpoints

### 1. Create a Designation

**Endpoint:**  
`POST /designations`

**Description:**  
Creates a new designation.

**Request Headers:**

```
{
    "Authorization": "Bearer <token>",
    "Content-Type": "application/json"
}
```

Request Body:


```
{
    "name": "Manager"
}
```

Response:
Success (200):

```
{
    "message": "Designation created successfully",
    "data": {
        "id": 1,
        "name": "Manager",
        "created_at": "2024-11-24T10:00:00.000Z",
        "updated_at": "2024-11-24T10:00:00.000Z"
    }
}
```

Validation Error (422):


```
{
    "message": "The name field is required."
}
```

## 2. Update a Designation


Endpoint:
post /designations/{id}

Description:
Updates an existing designation.

Request Headers:

```
{
    "Authorization": "Bearer <token>",
    "Content-Type": "application/json"
}
```

Request Body:


```
{
    "name": "Senior Manager"
}
```

Response:
Success (200):


```
{
    "message": "Designation updated successfully",
    "data": {
        "id": 1,
        "name": "Senior Manager",
        "created_at": "2024-11-24T10:00:00.000Z",
        "updated_at": "2024-11-24T10:05:00.000Z"
    }
}
```

Not Found (404):


```
{
    "message": "No query results for model [App\\Models\\Designation] 1"
}
```

## 3. Delete a Designation


Endpoint:
post /designations/{id}

Description:
Deletes an existing designation.

Request Headers:

```
{
    "Authorization": "Bearer <token>"
}
```

Response:
Success (200):


```
{
    "message": "Designation deleted successfully"
}
```

Not Found (404):


```
{
    "message": "No query results for model [App\\Models\\Designation] 1"
}
```

---

# Employee Management API Documentation

This API allows you to manage employees, including creating, updating, and deleting employee records. Each employee may have an associated user account.

## Base URL
http://your-api-url.com/api

## Authentication
All endpoints require authentication. Include the `Authorization` token in the request headers:
Authorization: Bearer (token)

---

## Endpoints

## 1. Create an Employee

**Endpoint:**  
`POST /employees`

**Description:**  
Creates a new employee and an associated user account.

**Request Headers:**
```
{
    "Authorization": "Bearer <token>",
    "Content-Type": "application/json"
}
```
Request Body:

```
{
    "employee_id": 101,
    "designation_id": 1,
    "name": "John Doe",
    "phone": "1234567890",
    "address": "123 Main Street",
    "national_id": 987654321,
    "blood_group": "O+",
    "image": "base64encodedimage",
    "created_by": 1
}
```
Response:

Success (200):
```
{
    "message": "Employee created successfully",
    "data": {
        "id": 1,
        "employee_id": 101,
        "designation_id": 1,
        "name": "John Doe",
        "phone": "1234567890",
        "address": "123 Main Street",
        "national_id": 987654321,
        "blood_group": "O+",
        "image": "base64encodedimage",
        "created_by": 1,
        "created_at": "2024-11-24T10:00:00.000Z",
        "updated_at": "2024-11-24T10:00:00.000Z"
    }
}
```
Validation Error (422):
```
{
    "message": "The employee_id field is required."
}
```
Error (500):
```
{
    "message": "Something went wrong",
    "data": "Error message"
}
```
## 2. Update an Employee
Endpoint:
post /employees/{id}

Description:
Updates an existing employee's data.

Request Headers:

```
{
    "Authorization": "Bearer <token>",
    "Content-Type": "application/json"
}
```
Request Body:

```
{
    "name": "Jane Doe",
    "phone": "9876543210",
    "address": "456 Elm Street",
    "designation_id": 2
}
```
Response:

Success (200):
```
{
    "message": "Employee updated successfully",
    "data": {
        "id": 1,
        "employee_id": 101,
        "designation_id": 2,
        "name": "Jane Doe",
        "phone": "9876543210",
        "address": "456 Elm Street",
        "national_id": 987654321,
        "blood_group": "O+",
        "image": "base64encodedimage",
        "created_by": 1,
        "created_at": "2024-11-24T10:00:00.000Z",
        "updated_at": "2024-11-24T10:05:00.000Z"
    }
}
```
Not Found (404):
```
{
    "message": "No query results for model [App\\Models\\Employee] 1"
}
```
Error (500):
```
{
    "message": "Something went wrong",
    "error": "Error message"
}
```
## 3. Delete an Employee
Endpoint:
post /employees/{id}

Description:
Deletes an employee and the associated user account (if any).

Request Headers:

```
{
    "Authorization": "Bearer <token>"
}
```
Response:

Success (200):
```
{
    "message": "Employee and associated user deleted successfully"
}
```
Not Found (404):
```
{
    "message": "No query results for model [App\\Models\\Employee] 1"
}
```
Error (500):
```
{
    "message": "Something went wrong",
    "error": "Error message"
}
```

## Create or Update User
Endpoint:
POST /user-create

Description:
Creates or updates a user associated with an employee, along with their permissions.

Request Headers:
```
{
    "Content-Type": "application/json",
    "Authorization": "Bearer <token>"
}
```
Request Body:
```
{
    "employee_id": 1,
    "email": "newuser@example.com",
    "password": "password123",
    "create": true,
    "view": true,
    "edit": false,
    "delete": false,
    "report": true
}
```
Response:

Success (200):
```
{
    "message": "User and permissions updated successfully",
    "data": {
        "id": 1,
        "employee_id": 1,
        "email": "newuser@example.com",
        "created_at": "2024-11-24T10:00:00.000Z",
        "updated_at": "2024-11-24T10:00:00.000Z"
    }
}
```
Validation Error (422):
```
{
    "message": "The email field is required."
}
```
Error (500):
```
{
    "message": "Something went wrong",
    "error": "Error details here"
}
```

# Employee Relation Management API Documentation

This document provides details for managing employee relations, including retrieving related employees and creating relations.

---

## Endpoints

### 1. **Get Related Employees**

**Endpoint:**  
`POST /get-related-employees`

**Description:**  
Retrieves employees related to the specified employee based on their designation hierarchy.

**Request Headers:**
```
{
    "Content-Type": "application/json",
    "Authorization": "Bearer <token>"
}
```
Request Body:

```
{
    "employee_id": 1
}
```
Response:

Success (200):
```
{
    "message": "Related employees retrieved successfully",
    "data": [
        {
            "id": 2,
            "name": "John Manager",
            "designation": "Manager",
            "phone": "123456789",
            "email": "manager@example.com"
        }
    ]
}
```
Validation Error (422):
```
{
    "message": "The employee_id field is required."
}
```
Error (500):
```
{
    "message": "An error occurred: Error details here"
}
```
Logic:

Officer: Retrieves all employees with the Manager designation.
Manager: Retrieves all employees with the RSM designation.
RSM: No related employees.

## 2. Create Employee Relation
Endpoint:
POST /store-relation

Description:
Creates a relation between two employees based on their designation hierarchy.

Request Headers:
```
{
    "Content-Type": "application/json",
    "Authorization": "Bearer <token>"
}
```
Request Body:

```
{
    "employee_id": 1,
    "relation_id": 2,
    "created_by": 1
}
```
Response:

Success (200):
```
{
    "message": "Relation created successfully",
    "data": {
        "id": 1,
        "employee_id": 1,
        "relation_id": 2,
        "created_by": 1
    }
}
```
Validation Error (422):
```
{
    "message": "Relation ID must be a Manager for an Officer."
}
```
Error (500):
```
{
    "message": "Something went wrong",
    "data": "Error details here"
}
```
