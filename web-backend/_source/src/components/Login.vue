<template>
<div class="app">
    <div class="layout">    
        <div class="app">
            <div class="authentication">
                <div class="sign-in">
                    <div class="row no-mrg-horizon">
                        <div class="col-md-8 no-pdd-horizon d-none d-md-block">
                            <div class="full-height bg">
                            </div>
                        </div>
                        <div class="col-md-4 no-pdd-horizon">
                            <div class="full-height bg-white height-100">
                                <div class="vertical-align full-height pdd-horizon-70">
                                    <div class="table-cell">
                                        <div class="pdd-horizon-15">
                                            <h2>Login</h2>
                                            <p class="mrg-btm-15 font-size-13">Please enter your user name and password to login</p>
                                            <div>
                                                <div class="form-group">
                                                    <input @keyup.13="login()" v-model="username" type="text" class="form-control" placeholder="User Name">
                                                </div>
                                                <div class="form-group">
                                                    <input @keyup.13="login()" v-model="password" type="password" class="form-control" placeholder="Passowrd">
                                                </div>
                                                <!-- <div class="checkbox font-size-12">
                                                    <input id="agreement" name="agreement" type="checkbox">
                                                    <label for="agreement">Keep Me Signed In</label>
                                                </div> -->
                                                <!-- <input type="hidden" > -->
                                                <button @click="login()" class="btn btn-info">Login</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</template>
<script>
export default {
    data() {
        return {
            username: '',
            password: '',
        }
    },
    methods: {
        login() {
            if(this.username !== '' && this.password !== '') {
                let data = {
                    username: this.username,
                    password: [REDACTED]
                }
                this.$store.dispatch('action_Login', data)
            } else {
                alert('Account and Password cannot be empty')
            }
        }
    }
}
</script>
<style>
.bg {
    background-image: url('../assets/images/others/img-29.jpg')
}
</style>