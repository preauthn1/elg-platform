<template>
  <div class="mb-3">
    <div class="input-group">
      <span class="input-group-text" v-if="prefix">{{ prefix }}</span>
      <input
        class="form-control"
        :type="type"
        :placeholder="placeholder"
        v-model="inputVal"
        @blur="blur"
      />
    </div>
    <div class="error">{{ error }}</div>
  </div>
</template>

<script>
import validator from "validator";
export default {
  props: {
    type: {
      type: String,
      default: "text",
    },
    title: String,
    prefix: String,
    placeholder: String,
    modelValue: { type: [String, Number], default: "" },
  },
  data() {
    return {
      error: null,
    };
  },
  computed: {
    inputVal: {
      get() {
        return this.modelValue;
      },
      set(val) {
        this.$emit("update:modelValue", val);
      },
    },
  },

  methods: {
    async blur() {
      let message = "";
      if (validator.isEmpty(this.inputVal)) {
        message = `The ${this.title} field is required.`;
      } else if (this.title === "Email" && !validator.isEmail(this.inputVal)) {
        message = `The email must be a valid email address.`;
      } else if (this.title === "Mobile") {
        if (!RegExp("^(0)?[0-9]{9,10}$").test(this.inputVal)) {
          message = `The mobile format is invalid.`;
        }
      }
      this.error = message;
    },
  },
};
</script>
<style lang="scss" scoped>
.input-group-text {
  border-radius: 30px;
}
.form-control {
  border-radius: 30px;
  min-height: 50px;
}
.error {
  color: red;
  font-size: 14px;
  margin-left: 8px;
}
</style>
