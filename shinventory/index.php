<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login Page</title>
  <!-- Font Awesome CDN -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    @import url('https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@300;400;600;700&display=swap');

    body {
      font-family: "Nunito Sans", sans-serif;
      margin: 0;
      padding: 0;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      background: url(../images/backbg.png) no-repeat center center;
      background-size: cover;
      background-attachment: fixed;
    }

    header {
      width: 100%;
      position: fixed;
      top: 0;
      left: 0;
      padding: 5px 2%;
      display: flex;
      align-items: center;
      background-color: #fff;
      z-index: 10;
    }

    .logo img {
      height: 4rem;
      padding: 10px 0 0 5px;
      transition: height 0.3s ease;
    }

    .card {
      display: flex;
      width: 900px;
      max-width: 95%;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
      margin: 80px 20px 20px;
    }

    .welcome-section {
      flex: 1;
      background-color: #600000;
      color: #fff;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: 40px;
      text-align: center;
      position: relative;
    }

    .greetings h1 {
      font-size: 2rem;
      margin-bottom: 10px;
      color: #fff;
      line-height: 1.3;
    }

    .greetings p {
      font-size: 1rem;
      color: #f1f1f1;
      line-height: 1.5;
    }

    .visit-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 20px;
      padding: 14px 28px;
      background-color: #fff;
      color: #600000;
      font-weight: 700;
      text-decoration: none;
      border-radius: 50px;
      font-size: 16px;
      transition: all 0.3s ease;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }

    .visit-button i {
      font-size: 16px;
      transition: transform 0.3s ease;
    }

    .visit-button:hover {
      background-color: #8b0000;
      color: #fff;
      transform: translateY(-2px);
      box-shadow: 0 6px 12px rgba(0, 0, 0, 0.2);
    }

    .visit-button:hover i {
      transform: translateX(4px);
    }

    .login-section {
      flex: 1;
      background-color: #fff;
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 40px;
    }

    .login-container {
      width: 100%;
      max-width: 320px;
    }

    .login-container h2 {
      text-align: center;
      margin-bottom: 20px;
      font-weight: 700;
      color: #600000;
      font-size: 1.8rem;
    }

    .login-form {
      display: flex;
      flex-direction: column;
    }

    .login-form span {
      font-weight: 600;
      margin-top: 1rem;
      color: #444;
      font-size: 0.95rem;
    }

    .input-wrapper {
      position: relative;
      width: 100%;
    }

    .login-form input {
      width: 100%;
      padding: 12px;
      margin-top: 8px;
      border: 1px solid #ddd;
      border-radius: 6px;
      box-sizing: border-box;
      font-size: 1rem;
      transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .login-form input:focus {
      outline: none;
      border-color: #600000;
      box-shadow: 0 0 0 2px rgba(96, 0, 0, 0.1);
    }

    .toggle-password {
      position: absolute;
      right: 12px;
      top: 60%;
      transform: translateY(-50%);
      cursor: pointer;
      font-size: 15px;
      color: #55565B;
      display: none; /* hidden by default */
      background: none;
      border: none;
      padding: 0;
    }

    .login-button {
      width: 100%;
      padding: 12px;
      margin-top: 20px;
      font-size: 16px;
      font-weight: 600;
      background-color: #600000;
      color: #fff;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .login-button:hover {
      background-color: #8b0000;
      transform: translateY(-1px);
      box-shadow: 0 4px 8px rgba(96, 0, 0, 0.3);
    }

    .login-button:active {
      transform: translateY(0);
    }

    .login-help {
      margin-top: 20px;
      font-size: 14px;
      text-align: center;
      color: #555;
      line-height: 1.4;
    }

    .login-help a {
      color: #600000;
      font-weight: 600;
      text-decoration: none;
      transition: color 0.3s ease;
    }

    .login-help a:hover {
      text-decoration: underline;
      color: #8b0000;
    }

    /* ===== RESPONSIVE DESIGN ===== */

    /* Large tablets and small desktops (1024px to 1199px) */
    @media (max-width: 1199px) {
      .card {
        width: 850px;
        margin: 70px 15px 15px;
      }
      
      .welcome-section,
      .login-section {
        padding: 35px;
      }
      
      .greetings h1 {
        font-size: 1.8rem;
      }
    }

    /* Tablets (768px to 1023px) */
    @media (max-width: 1023px) {
      body {
        padding: 20px 0;
        align-items: flex-start;
      }
      
      .card {
        width: 700px;
        margin: 60px 15px 15px;
      }
      
      .welcome-section,
      .login-section {
        padding: 30px;
      }
      
      .greetings h1 {
        font-size: 1.6rem;
      }
      
      .greetings p {
        font-size: 0.95rem;
      }
      
      .login-container h2 {
        font-size: 1.6rem;
      }
      
      .visit-button {
        padding: 12px 24px;
        font-size: 15px;
      }
    }

    /* Large phones and small tablets (600px to 767px) */
    @media (max-width: 767px) {
      body {
        padding: 15px 0;
        background-attachment: scroll;
      }
      
      header {
        padding: 5px 4%;
      }
      
      .logo img {
        height: 3.5rem;
        padding: 8px 0 0 0;
      }
      
      .card {
        flex-direction: column;
        width: 90%;
        max-width: 450px;
        margin: 60px auto 20px;
      }
      
      .welcome-section {
        padding: 30px 25px;
        order: 2;
      }
      
      .login-section {
        padding: 30px 25px;
        order: 1;
      }
      
      .greetings h1 {
        font-size: 1.5rem;
        margin-bottom: 8px;
      }
      
      .greetings p {
        font-size: 0.9rem;
      }
      
      .visit-button {
        margin-top: 15px;
        padding: 10px 20px;
        font-size: 14px;
      }
      
      .login-container h2 {
        font-size: 1.5rem;
        margin-bottom: 15px;
      }
      
      .login-form span {
        font-size: 0.9rem;
      }
      
      .login-form input {
        padding: 10px;
        font-size: 0.95rem;
      }
      
      .login-button {
        padding: 10px;
        font-size: 15px;
      }
    }

    /* Small phones (480px to 599px) */
    @media (max-width: 599px) {
      body {
        padding: 10px 0;
      }
      
      header {
        padding: 5px 3%;
      }
      
      .logo img {
        height: 3rem;
      }
      
      .card {
        width: 95%;
        margin: 50px auto 15px;
        border-radius: 10px;
      }
      
      .welcome-section,
      .login-section {
        padding: 25px 20px;
      }
      
      .greetings h1 {
        font-size: 1.3rem;
      }
      
      .greetings p {
        font-size: 0.85rem;
      }
      
      .visit-button {
        padding: 9px 18px;
        font-size: 13px;
      }
      
      .login-container h2 {
        font-size: 1.3rem;
      }
      
      .login-form span {
        margin-top: 0.8rem;
        font-size: 0.85rem;
      }
      
      .login-form input {
        padding: 9px;
        font-size: 0.9rem;
      }
      
      .login-button {
        margin-top: 15px;
        padding: 9px;
        font-size: 14px;
      }
      
      .login-help {
        margin-top: 15px;
        font-size: 13px;
      }
    }

    /* Very small phones (479px and below) */
    @media (max-width: 479px) {
      body {
        padding: 5px 0;
      }
      
      header {
        padding: 3px 2%;
      }
      
      .logo img {
        height: 2.5rem;
      }
      
      .card {
        width: 98%;
        margin: 45px auto 10px;
        border-radius: 8px;
      }
      
      .welcome-section,
      .login-section {
        padding: 20px 15px;
      }
      
      .greetings h1 {
        font-size: 1.2rem;
      }
      
      .greetings p {
        font-size: 0.8rem;
      }
      
      .visit-button {
        padding: 8px 16px;
        font-size: 12px;
        margin-top: 12px;
      }
      
      .login-container h2 {
        font-size: 1.2rem;
        margin-bottom: 12px;
      }
      
      .login-form span {
        margin-top: 0.7rem;
        font-size: 0.8rem;
      }
      
      .login-form input {
        padding: 8px;
        font-size: 0.85rem;
        margin-top: 5px;
      }
      
      .toggle-password {
        right: 8px;
        font-size: 14px;
      }
      
      .login-button {
        padding: 8px;
        font-size: 13px;
        margin-top: 12px;
      }
      
      .login-help {
        margin-top: 12px;
        font-size: 12px;
      }
    }

    /* Landscape orientation for mobile */
    @media (max-height: 600px) and (orientation: landscape) {
      body {
        align-items: flex-start;
        padding-top: 10px;
      }
      
      .card {
        margin-top: 50px;
        max-height: 85vh;
        overflow-y: auto;
      }
      
      .welcome-section,
      .login-section {
        padding: 20px;
      }
      
      .greetings h1 {
        font-size: 1.3rem;
        margin-bottom: 5px;
      }
      
      .greetings p {
        font-size: 0.85rem;
        margin-bottom: 5px;
      }
      
      .visit-button {
        margin-top: 10px;
        padding: 8px 16px;
        font-size: 13px;
      }
    }

    /* High DPI screens */
    @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
      .card {
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
      }
      
      .visit-button {
        box-shadow: 0 3px 6px rgba(0, 0, 0, 0.12);
      }
    }

    /* Print styles */
    @media print {
      body {
        background: #fff !important;
      }
      
      .card {
        box-shadow: none;
        border: 1px solid #ddd;
      }
      
      .visit-button {
        display: none;
      }
      
      .login-button {
        background: #333 !important;
        color: #fff !important;
      }
    }

    /* Reduced motion support */
    @media (prefers-reduced-motion: reduce) {
      * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
      }
      
      .visit-button:hover i,
      .visit-button:hover,
      .login-button:hover {
        transform: none;
      }
    }

    /* Dark mode support */
    @media (prefers-color-scheme: dark) {
      .login-section {
        background-color: #fff;
      }
      
      .login-container h2 {
        color: #600000;
      }
      
      .login-form span {
        color: #000000ff;
      }
      
      .login-form input {
        background-color: #fff;
        border-color: #444;
        color: #000000ff;
      }
      
      .login-form input:focus {
        border-color: #ff6b6b;
      }
      
      .login-help {
        color: #b0b0b0;
      }
      
      .login-help a {
        color: #ff6b6b;
      }
    }
</style>
</head>
<body>
  <header>
    <div class="logo">
      <img src="../images/logo1.png" alt="Serving Hearts Logo">
    </div>
  </header>

  <div class="card">
    <!-- Left Section -->
    <div class="welcome-section">
      <div class="greetings">
        <h1>Serving Hearts Charity Inc.</h1>
        <p>"Visit our website to know more about our mission and work."</p>
        <a href="http://localhost/servinghearts/home.html" class="visit-button">
          Visit Public Site <i class="fa-solid fa-arrow-right"></i>
        </a>
      </div>
    </div>

    <!-- Right Section -->
    <div class="login-section">
      <div class="login-container">
        <h2>Staff / Admin Login</h2>
        <form class="login-form" action="authenticate.php" method="post">
          <span>Username</span>
          <input type="text" name="username" placeholder="username" required>
          
          <span>Password</span>
          <div class="input-wrapper">
            <input type="password" id="password" name="password" placeholder="password" required>
            <i class="fa-solid fa-eye-slash toggle-password" id="togglePassword"></i>
          </div>
          
          <button type="submit" class="login-button">Login</button>
        </form>

        <div class="login-help">
          <p>Can't login? <a href="mailto:admin@organization.com">Email the administrator</a></p>
        </div>
      </div>
    </div>
  </div>

  <script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    // Show eye only if typing
    passwordInput.addEventListener('input', function () {
      if (passwordInput.value.length > 0) {
        togglePassword.style.display = 'block';
      } else {
        togglePassword.style.display = 'none';
        passwordInput.type = 'password';
        togglePassword.classList.remove('fa-eye');
        togglePassword.classList.add('fa-eye-slash');
      }
    });

    togglePassword.addEventListener('click', function () {
      const isPasswordHidden = passwordInput.type === 'password';
      passwordInput.type = isPasswordHidden ? 'text' : 'password';
      this.classList.toggle('fa-eye', isPasswordHidden);
      this.classList.toggle('fa-eye-slash', !isPasswordHidden);
    });
  </script>
</body>
</html>
