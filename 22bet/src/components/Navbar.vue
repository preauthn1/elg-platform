<template>
  <nav>
    <img src="../assets/logo.png" width="104" height="41" />
  </nav>
  <div class="nav-height"></div>
</template>

<style lang="scss" scoped>
$height: 55.67px;
nav {
  height: $height;
  background: #054247;
  position: fixed;
  width: 100%;
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 1000;
}
.nav-height {
  height: $height;
}
</style>
