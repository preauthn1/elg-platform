<template>
  <div class="hello">
    <h1>{{ msg }}</h1>
    <h2>By HTML : {{ this.$route.params.id }}</h2>
    <h2>By JS : {{ test }}</h2>
    <h2>global API URL By JS : {{ api_url }}</h2>
    <h2>global API URL By HTML: {{ $API_URL }}</h2>
    <h2>{{ $t("hello") }}</h2>
    <input :placeholder="$t('hello')" />
  </div>
</template>

<script>
export default {
  name: 'HelloWorld',
  data () {
    return {
      msg: 'Welcome to Your Vue.js App',
      test: this.$route.params.id,
      api_url: this.$API_URL
    }
  }
}
</script>
