<template>
  <v-container fluid fill-height class="pa-0 ma-0 white" id="profile">
    <v-layout column class="pa-0 ma-0">
      <v-card class="elevation-0">
        <!-- <v-card-title style="background-image:url('/static/bg/4.jpg')">
            <v-layout column align-center justify-center>
                <v-avatar size="200" class="mx-5">
                    <img v-if="get_friendView.thumbnail && get_friendView.thumbnail!=='null'" :src="get_friendView.thumbnail">
                    <img v-else :src="defaultAvatar">
                </v-avatar>
                <h1 class="white--text">{{get_friendView.username}}</h1>
            </v-layout>
        </v-card-title>-->
        <v-card-media height="350" src="/static/bg/4.jpg">
          <v-layout column align-center justify-center>
            <v-avatar size="180" class="mx-5">
              <img
                v-if="get_friendView.thumbnail && get_friendView.thumbnail!=='null'"
                :src="get_friendView.thumbnail"
              />
              <img v-else :src="defaultAvatar" />
            </v-avatar>
            <h2 class="white--text">{{get_friendView.username}}</h2>
          </v-layout>
        </v-card-media>
        <v-card-text class="pa-0">
          <!-- note 功能 先關閉 -->
          <router-view></router-view> 
        </v-card-text>
      </v-card>
    </v-layout>
  </v-container>
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
export default {
  computed: {
    ...mapGetters(['get_friendView'])
  },
  data: () => ({
    defaultAvatar: require('@/../static/avatar/user.png')
  })
}
</script>
<style  scoped>
/* @media only screen and (max-width: 959px) {
  .content--wrap {
    -webkit-box-flex: 1;
    -ms-flex: 1 1 auto;
    flex: 1 1 auto;
    max-width: 100%;
    margin-top: 56px;
  }
} */
</style>