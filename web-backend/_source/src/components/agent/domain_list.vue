<template>
<!-- 未使用!!!! 寫在agent index.vue -->
<div>
<div class="modal-body height-160">
	<a class="modal-close" href="javascript:void(0)" data-dismiss="modal" style="float: right;" @click="domainClose()">
        <i class="ti-close"></i>
    </a>
    <div class="vertical-align text-center">
        <div class="table-cell">
            <div class="container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-block">
                                <h4 class="card-title">網域清單</h4>
                                <button id="add" data-uid="<?=$_POST['id']?>" data-toggle="modal" data-target="#domain_add" class="btn btn-sm btn-success btn-rounded">新增網域</button>
                                <p>可於下方清單管理啟用狀態及移除</p>
                                <div class="table-overflow">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>域名</th>
                                                <th>啟用狀態</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(domain,i) in getAgent_Domain_List" :key="i">
                                                <td>{{domain.domain}}</td>
                                                <td>
                                                    <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                                                        <input type="checkbox" name="status" id="status<?=$domain['id']?>" data-id="<?=$domain['id']?>" :status="domain.status == 1 ? 0 : 1" class="domain_toggle_status" :checked="domain.status==1">
                                                        <label :for="domain.id"></label>
                                                    </div>
                                                </td>
                                                <td>
                                                    <a href="" class="btn btn-danger btn-rounded btn-sm delete" data-id="<?=$domain['id']?>" >刪除網域</a>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
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
import { mapGetters } from 'vuex'
import DomainAdd from './domain_add'
export default {
    components: {
        DomainAdd
    },
    computed: {
        ...mapGetters(['getAgent_Domain_List'])
    },
    methods: {
        domainClose() {
            // closeDominList是在父组件on监听的方法
            // 第二个参数false是需要传的值
            this.$emit('closeDomainList', false)
        }
    },
    created() {
        
    }
}
</script>