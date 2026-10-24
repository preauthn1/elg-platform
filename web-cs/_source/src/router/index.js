import Vue from "vue";
import Router from "vue-router";
import HelloWorld from "@/components/HelloWorld";

Vue.use(Router);

export default new Router({
  mode: "history",
  routes: [
    {
      path: "/404",
      component: () =>
        import(/* webpackChunkName: "groupchatfriends" */ "@/components/404")
    },
    {
      path: "/",
      name: "Home",
      component: () =>
        import(/* webpackChunkName: "home" */ "@/components/Home"),
      mata: {},
      children: [
        {
          path: "/TalkList",
          name: "TalkList",
          meta: {
            friendtalkMenu: true
          },
          component: () =>
            import(/* webpackChunkName: "talklist" */ "@/components/TalkList")
        },

        {
          path: "/MyQRcode",
          meta: {
            title: "我的QR Code",
            back: true
            // unblock: true,
          },
          component: () =>
            import(/* webpackChunkName: "myqrcode" */ "@/components/MyQRcode")
        },
        {
          path: "/QRcodeSearch",
          meta: {
            title: "行動條碼掃描器",
            back: true
            // unblock: true,
          },
          component: () =>
            import(
              /* webpackChunkName: "qrcodesearch" */ "@/components/QRcodeSearch"
            )
        },

        {
          path: "/AddFriend",
          meta: {
            title: "addFriends",
            back: true
            // unblock: true,
          },
          component: () =>
            import(/* webpackChunkName: "addfriend" */ "@/components/AddFriend")
        },

        {
          path: "/TalkRedEnvelopes",
          meta: {
            title: "",
            back: true,
            talkMenu: true
            // unblock: true,
          },
          component: () =>
            import(
              /* webpackChunkName: "talkredenvelopes" */ "@/components/TalkRedEnvelopes"
            )
        },
        {
          path: "/RedEnvelopes/:roomId",
          meta: {
            title: "redEnvelope",
            back: true
            //   talkMenu: true,
            // unblock: true,
          },
          component: () =>
            import(
              /* webpackChunkName: "redenvelopes" */ "@/components/RedEnvelopes"
            )
        },

        {
          path: "/RedLogin/:redEnvelId",
          meta: {
            title: "enterUserPassword",
            back: true
            //   talkMenu: true,
            // unblock: true,
          },
          component: () =>
            import(/* webpackChunkName: "redlogin" */ "@/components/RedLogin")
        },
        {
          path: "/RedReceive",
          name: "RedReceive",
          meta: {
            title: "紅包發送紀錄",
            back: true
            //   talkMenu: true,
            // unblock: true,
          },
          component: () =>
            import(
              /* webpackChunkName: "redreceive" */ "@/components/RedEnvelopesRecord"
            )
        },
        {
          path: "/RedSend",
          name: "RedSend",
          meta: {
            title: "紅包發送紀錄",
            back: true
            //   talkMenu: true,
            // unblock: true,
          },
          component: () =>
            import(
              /* webpackChunkName: "redsend" */ "@/components/RedEnvelopesRecord"
            )
        },
        {
          path: "/BlackList",
          meta: {
            title: "blockList",
            back: true,
            unblock: true
          },
          component: () =>
            import(/* webpackChunkName: "blacklist" */ "@/components/BlackList")
        },

        {
          path: "/SearchTalk",
          meta: {
            title: "搜尋聊天內容",
            back: true
          },
          component: () =>
            import(
              /* webpackChunkName: "searchtalk" */ "@/components/SearchTalk"
            )
        },
        {
          path: "/GroupName",
          meta: {
            title: "群組名稱",
            back: true
            // unblock: true,
          },
          component: () =>
            import(/* webpackChunkName: "groupname" */ "@/components/GroupName")
        },
        {
          path: "/GroupTalk",
          meta: {
            title: "群組(10)",
            back: true,
            groupChat: true,
            groupname: true
            // unblock: true,
          },
          component: () =>
            import(/* webpackChunkName: "grouptalk" */ "@/components/Talk")
        },
        {
          path: "/GroupRedEnvelopes",
          meta: {
            title: "發紅包(群)",
            back: true,
            groupChat: true
          },
          component: () =>
            import(
              /* webpackChunkName: "groupredenvelopes" */ "@/components/GroupRedEnvelopes"
            )
        },
        {
          path: "/GroupRedEnvel",
          meta: {
            title: "群組(13)-隨機紅包",
            back: true,
            groupChat: true
          },
          component: () =>
            import(
              /* webpackChunkName: "groupredenvel" */ "@/components/GroupRedEnvel"
            )
        },
        {
          path: "/SetGroupFile",
          meta: {
            title: "設定群組檔案",
            back: true,
            build: true
          },
          component: () =>
            import(
              /* webpackChunkName: "setgroupfile" */ "@/components/SetGroupFile"
            )
        },
        {
          path: "/GroupChatFriends",
          meta: {
            title: "選擇好友",
            back: true,
            next: true
          },
          component: () =>
            import(
              /* webpackChunkName: "groupchatfriends" */ "@/components/GroupChatFriends"
            )
        },
        {
          path: "/test",
          meta: {
            title: "選擇好友",
            back: true,
            next: true
          },
          component: () =>
            import(
              /* webpackChunkName: "groupchatfriends" */ "@/components/test"
            )
        },

        // {
        //   path: '/SetName',
        //   meta: {
        //     title: '設定別稱',
        //     back: true,
        //     // unblock: true,
        //   },
        //   component: () =>
        //     import(/* webpackChunkName: "setname" */ '@/components/SetName'),
        // },
        {
          path: "/TalkByCode/:talkcode",
          name: "talk",
          meta: {
            by: "code",
            title: "",
            talkback: true
            // talkMenu: true,
            // unblock: true,
          },
          component: () =>
            import(/* webpackChunkName: "talk" */ "@/components/Talk")
        },
        {
          path: "/TalkById/:friendId",
          name: "talk",
          meta: {
            by: "id",
            title: "",
            talkback: true
            // talkMenu: true,
            // unblock: true,
          },
          component: () =>
            import(/* webpackChunkName: "talk" */ "@/components/Talk")
        },
        {
          path: "/SetName/:friendId/:nickname",
          name: "setname",
          meta: {
            title: "設定別稱",
            back: true
            // unblock: true,
          },
          component: () =>
            import(/* webpackChunkName: "setname" */ "@/components/SetName")
        },
        {
          path: "/SetId",
          name: "setid",
          meta: {
            title: "setId",
            back: true
            // unblock: true,
          },
          component: () =>
            import(/* webpackChunkName: "setid" */ "@/components/SetId")
        },
        {
          path: "/",
          name: "Main",
          meta: {
            // title: '聊 天',
            // back: true,
            // unblock: true,

            friendtalkMenu: true
          },
          component: () =>
            import(/* webpackChunkName: "main" */ "@/components/Main")
        },
        {
          path: "/:sign",
          name: "Main",

          meta: {
            // title: '聊 天',
            // back: true,
            // unblock: true,

            friendtalkMenu: true
          },
          component: () =>
            import(/* webpackChunkName: "main" */ "@/components/Main")
        }
      ]
    }
  ]
});
