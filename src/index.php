<?php
// src/intro.php
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>CollabLens</title>
  <link rel="icon" type="image/x-icon" href="/reasources/baj_logo.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/css/uikit.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/js/uikit.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/uikit@3.17.11/dist/js/uikit-icons.min.js"></script>

  <style>
    /* Translucent dark navbar with subtle blur */
    .site-navbar-overlay {
      width: 100%;
      background: rgba(0, 0, 0, 0.35);      /* darkness */
      backdrop-filter: saturate(160%) blur(6px);
      -webkit-backdrop-filter: saturate(160%) blur(6px);
      padding: 6px 0;
    }

    /* Keep primary button colored even inside uk-light/uk-section-primary */
    .uk-preserve-color.uk-button-primary {
      filter: none !important;
      color: inherit; /* UIkit will keep proper contrast */
    }

    .custom-login-btn {
      background: #1e87f0;   /* base (UIkit’s default primary) */
      color: #fff;           /* make sure text is white */
      border: none;
    }

    .custom-login-btn:hover {
      background: #0f7ae5;   /* slightly darker on hover */
      color: #fff;
    }
</style>
</head>
<body>

  <!-- Nav Bar -->
  <nav class="site-navbar-overlay uk-position-fixed uk-position-top uk-light" style="z-index: 3;">
    <div class="uk-container">
      <div uk-navbar>
        <div class="uk-navbar-left">
          <a class="uk-navbar-item uk-logo" href="./" aria-label="Home">
            <img src="/reasources/baj_logo.svg" alt="BAJ Logo" style="height: 85px;">
          </a>
        </div>

        <div class="uk-navbar-right">
          <ul class="uk-navbar-nav uk-visible@s">
            <li class="uk-active"><a href="./">Home</a></li>
            
          </ul>

          <div class="uk-navbar-item">
            <a href="./login.php" class="uk-button custom-login-btn">Login</a>
          </div>

          <ul class="uk-navbar-nav uk-visible@s">
          <li><a href="./signUp.php">Sign Up</a></li>
          </ul>

        </div>
      </div>
    </div>
  </nav>

  <!-- Hero Section with Video Background -->
  <section class="uk-section uk-padding-remove" style="position: relative; overflow: hidden; height: 100vh;">
    <video autoplay muted loop playsinline
      style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0;">
      <source src="/reasources/BAJ-Back-Vid.mp4" type="video/mp4">
    </video>

    <!-- Content Overlay -->
    <div class="uk-container uk-position-relative uk-flex uk-flex-middle uk-flex-center uk-text-center"
        style="height: 100%; z-index: 2; color: #fff;">
      <div>
        <h1 class="uk-heading-medium uk-light">Facilitate Great Student Collaboration</h1>
        <p class="uk-text-lead uk-light">Create class groups, Discord and SharePoint spaces where you can monitor and facilitate student work.</p>
        <a class="uk-button uk-button-primary uk-button-large uk-preserve-color" href="./login.php">Get Started</a>
      </div>
    </div>
  </section>


  <!-- Features Overview -->
  <section class="uk-section uk-section-default">
    <div class="uk-container">
      <div class="uk-grid-match uk-child-width-1-3@m" uk-grid>
        <div>
          <div class="uk-card uk-card-default uk-card-body uk-text-center">
            <span uk-icon="icon: users; ratio: 2"></span>
            <h3 class="uk-card-title">Great Groups</h3>
            <p>Create class groups, Discord and SharePoint spaces where you can monitor and fcilitate student groups.</p>
          </div>
        </div>
        <div>
          <div class="uk-card uk-card-default uk-card-body uk-text-center">
            <span uk-icon="icon: settings; ratio: 2"></span>
            <h3 class="uk-card-title">Group Analytics</h3>
            <p>Ever wonder how individual students contribute. Gather insight into group dynamics and collaboration.</p>
          </div>
        </div>
        <div>
          <div class="uk-card uk-card-default uk-card-body uk-text-center">
            <span uk-icon="icon: clock; ratio: 2"></span>
            <h3 class="uk-card-title">Real-Time Updates</h3>
            <p>Get information fast with up-to-date communication and collaberation data from Discord and SharePoint.</p>
          </div>
        </div>
      </div>
    </div>
  </section>


  <footer class="uk-section uk-section-small uk-section-muted uk-text-center">
    <div class="uk-container">
      <p>© 2025 Lorand Kis. All rights reserved.</p>
    </div>
  </footer>

</body>
</html>