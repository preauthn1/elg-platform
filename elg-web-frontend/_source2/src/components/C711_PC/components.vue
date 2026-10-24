<template>
<div>
    <!-- 外橘內黑框 灰黑漸層-->
    <div class="ele-border"><input class="ele_border_in gradual-black" type="text" placeholder="用戶名/手機號碼 (國碼+號碼)"></div>
    <!-- 外橘內黑框 紅黑漸層-->
    <div class="ele-border"><button class="ele_border_in gradual-red">登入</button></div>
    <!-- 外橘內黑框 金漸層-->
    <div class="ele-border"><button class="ele_border_in gradual-gold">註冊</button></div>
    <!-- 外橘內黑框 灰漸層-->
    <div class="ele-border"><button class="ele_border_in gradual-grey">忘記密碼</button></div>

    <!-- 外橘無內框 深棕色 -->
    <div class="ele-border"><input class="ele_border_none dark-brown" type="text" placeholder="帳號"></div>
    <!-- 無框按鈕 原色天藍 -->
    <div class="btn-blue"><button>註冊</button></div>
    <!-- 無框按鈕 原色灰 -->
    <div class="btn-grey"><button>註冊</button></div>

    <!-- 輸入驗證 含x -->
    <div class="validateDiv">
        <div class="ele-border input-X">
            <div class="dark-brown">
                <span class="inputFontLeft">+{{ phoneCode!==null?phoneCode:phoneCode}}</span>
                <input v-model="phone" name="phone" @keyup="send_disable()" v-validate="{ required:true,regex:phoneRule }" :data-vv-as="$t('cellphoneNumber')" class="ele_border_none dark-brown"  type="tel" :placeholder="$t('cellphoneNumber')">
                <img
                    src="../../../static/img/C711_PC/grayClose.png"
                    class="inputClear"
                    @click="resetphone()"
                />
            </div>
        </div>
        <label v-show="errors.has('phone')" for="phone" class="validate_content">{{ errors.first('phone') }}</label>
    </div>

    <!--  外橘內黑框 灰黑漸層 左標題 右文字 -->
    <div class="ele-border input-X">
        <div>
            <span class="inputFontLeft">{{$t('account')}}</span>
            <p class="inputFontRight ele_border_in gradual-black">{{newPassword}}</p>
        </div>
    </div>

    <!-- 改密碼成功彈窗 -->
    <div v-if="isResetNewpass" class="popUp_block">
        <div class="wrap">
            <div class="title">{{ $t("prompt") }}</div>
            <div class="content">
                <div>{{ $t("passwordupdated") }}</div>
            </div>
            <div class="footer">
                <a href="/">
                    <div class="btn-blue"><button>{{ $t("confirm") }}</button></div>
                </a>
            </div>
        </div>
    </div>
</div>
</template>