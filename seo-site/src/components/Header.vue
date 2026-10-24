<template>
  <header>
    <div class="logo">
      <img src="../assets/img/logo.png" />
    </div>

    <nav class="d-flex">
      <a
        href="https://example.invalid/signup"
        class="link sign-up-link"
      >
        註冊
      </a>
      <a href="https://example.invalid/login" class="link login-link">
        登入
      </a>
    </nav>
  </header>
</template>

<script>
export default {};
</script>

<style scoped>
.logo {
  height: 56px;
  display: flex;
  justify-content: center;
  align-items: center;
}

.logo img {
  height: 35px;
}

.link {
  height: 36px;
  width: 100%;
  margin: 0 8px;
  color: #fff;
  border-radius: 15px;
  display: flex;
  justify-content: center;
  align-items: center;
  text-decoration: none;
}

.sign-up-link {
  background: #0d3e79;
  border: 2px solid #1884ff;
}

.login-link {
  background: #0059e7;
}
</style>
