export var Router = [
    {
        path: "/Login",
        component: resolve => require(["@/components/Login"], resolve),
    },
    {
        path: "/",
        meta: {},
        component: resolve => require(["@/components/Home"], resolve),
        children: [
            {
                // path: "/Lobby",
                // component: resolve =>require(["@/components/C711_PC/Lobby_list"], resolve)
            }
        ]
    }
]
export default Router;