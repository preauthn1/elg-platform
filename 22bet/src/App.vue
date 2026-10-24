<template>
  <Signup />
</template>

<script>
import Signup from './pages/Signup.vue';

export default {
  name: 'App',
  components: {
    Signup,
  },
};
</script>

<style>
#app {
  font-family: 'Roboto Condensed', Avenir, Helvetica, Arial, sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

body {
  margin: 0;
}
</style>
