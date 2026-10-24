<template>
  <div class="hello">
    <h1>{{ msg }}</h1>
    <h2>Essential Links</h2>
    <ul>
      <li>
        <a
          href="https://example.invalid"
          target="_blank"
        >
          Core Docs
        </a>
      </li>
      <li>
        <a
          href="https://example.invalid"
          target="_blank"
        >
          Forum
        </a>
      </li>
      <li>
        <a
          href="https://example.invalid"
          target="_blank"
        >
          Community Chat
        </a>
      </li>
      <li>
        <a
          href="https://example.invalid/vuejs"
          target="_blank"
        >
          Twitter
        </a>
      </li>
      <br>
      <li>
        <a
          href="http://example.invalid/webpack/"
          target="_blank"
        >
          Docs for This Template
        </a>
      </li>
    </ul>
    <h2>Ecosystem</h2>
    <ul>
      <li>
        <a
          href="http://example.invalid/"
          target="_blank"
        >
          vue-router
        </a>
      </li>
      <li>
        <a
          href="http://example.invalid/"
          target="_blank"
        >
          vuex
        </a>
      </li>
      <li>
        <a
          href="http://example.invalid/"
          target="_blank"
        >
          vue-loader
        </a>
      </li>
      <li>
        <a
          href="https://example.invalid/vuejs/awesome-vue"
          target="_blank"
        >
          awesome-vue
        </a>
      </li>
    </ul>
  </div>
</template>

<script>
export default {
  name: 'HelloWorld',
  data () {
    return {
      msg: 'Welcome to Your Vue.js App'
    }
  }
}
</script>

<!-- Add "scoped" attribute to limit CSS to this component only -->
<style scoped>
h1, h2 {
  font-weight: normal;
}
ul {
  list-style-type: none;
  padding: 0;
}
li {
  display: inline-block;
  margin: 0 10px;
}
a {
  color: #42b983;
}
</style>
