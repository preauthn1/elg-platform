import Global from '@/Global'
import * as types from '../mutations_type'
import store from '@/store'
import router from '@/router'
import axios from 'axios'
import secret from '@/util/secret'
import md5 from 'js-md5'

const state = {
    noteList: [],
    noteContent_List: []
}

const actions = {
    // note list
    action_NoteList({ commit }, data) {
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            let jsonMsg = JSON.stringify({
                "room_id":data.room_id,
                "page": 1, 
                "per_page": 1000
            })
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/note/list', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token},
            })
            .then(res => {
                if (res.data.code == 1000) {
                    commit(types.GET_NOTE_LIST, res.data.data)
                } else {
                    alert(res.data.message)
                }
            })
            .catch(error => {
                console.log(error)
            })
        }
    },
    // 新增 note title
    action_addNote_Title ({commit}, addData) {
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            let jsonMsg = JSON.stringify({
                "room_id": addData.room_id,
                "title": addData.title, 
                "status": addData.status
            })
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/note/add', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token},
            })
            .then(res => {
                if (res.data.code == 1000) {
                    let room_id = { "room_id": addData.room_id}
                    store.dispatch('action_NoteList', room_id) //更新列表
                } else {
                    alert(res.data.message)
                }
            })
            .catch(error => {
                console.log(error)
            })
        }
    },
    // 編輯 note title
    action_editNote_Title ({commit}, editData) {
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            let jsonMsg = JSON.stringify({
                "note_id": editData.note_id,
                "title": editData.title, 
                "status": editData.status
            })
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/note/edit', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token},
            })
            .then(res => {
                if (res.data.code == 1000) {
                    let room_id = { "room_id": editData.room_id}
                    store.dispatch('action_NoteList', room_id) //更新列表
                } else {
                    alert(res.data.message)
                }
            })
            .catch(error => {
                console.log(error)
            })
        }
    },
    // noteContent list
    action_NoteContentList({ commit }, data) {
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            let jsonMsg = JSON.stringify({
                "note_id":data.note_id,
                "page": 1, 
                "per_page": 1000
            })
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/note_content/list', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token},
            })
            .then(res => {
                if (res.data.code == 1000) {
                    commit(types.GET_NOTE_CONTENT_LIST, res.data.data)
                } else {
                    alert(res.data.message)
                }
            })
            .catch(error => {
                console.log(error)
            })
        }
    },
    // 新增內容
    action_addNote_Content ({commit}, addData) {
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            let jsonMsg = JSON.stringify({
                "note_id": addData.note_id,
                "content": addData.content,
            })
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/note_content/add', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token},
            })
            .then(res => {
                if (res.data.code == 1000) {
                    let note_id = { "note_id": addData.note_id}
                    store.dispatch('action_NoteContentList', note_id) //更新列表
                } else {
                    alert(res.data.message)
                }
            })
            .catch(error => {
                console.log(error)
            })
        }
    },
    // 編輯內容
    action_editNote_Content ({commit}, editData) {
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            let jsonMsg = JSON.stringify({
                "content_id": editData.content_id,
                "content": editData.content,
            })
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/note_content/edit', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token},
            })
            .then(res => {
                if (res.data.code == 1000) {
                    let note_id = { "note_id": editData.note_id}
                    store.dispatch('action_NoteContentList', note_id) //更新列表
                } else {
                    alert(res.data.message)
                }
            })
            .catch(error => {
                console.log(error)
            })
        }
    },
    // 刪除內容
    action_delete_Content ({commit}, delData) {
        let token = [REDACTED]'user_token')
        if(token){
            let md5_key = Global.$API_MD5_KEY
            let jsonMsg = JSON.stringify({
                "content_id": delData.content_id,
            })
            let params = secret.Encrypt(jsonMsg)
            let key = md5(params + md5_key)
            axios.post( Global.$API_URL + 'api/note_content/delete', {
                params: params,
                key: key,
            }, { 
                headers: {'Accept-Language': Global.$LOCALE_LANG,'Authorization': "Bearer " + token},
            })
            .then(res => {
                if (res.data.code == 1000) {
                    let note_id = { "note_id": delData.note_id}
                    store.dispatch('action_NoteContentList', note_id) //更新列表
                } else {
                    alert(res.data.message)
                }
            })
            .catch(error => {
                console.log(error)
            })
        }
    },
}

const mutations = {
    [types.GET_NOTE_LIST](state, noteList) {
        state.noteList = noteList
        // console.log('noteList',state.noteList)
    },
    [types.GET_NOTE_CONTENT_LIST](state, contentList) {
        state.noteContent_List = contentList
        // console.log('noteContent_List',state.noteContent_List)
    },
}

const getters = {
    get_noteList: state => state.noteList,
    get_noteContent_List: state => state.noteContent_List,
    
}
export default {
    state,
    actions,
    mutations,
    getters,
}